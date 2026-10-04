<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Search\FileTextExtractor;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class LegacyFileTextTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/legacy-'.uniqid();
        mkdir($this->dir);
        config(['services.text_extraction.tools' => ['catdoc' => true, 'xls2csv' => true, 'catppt' => true, 'msgconvert' => true]]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function file(string $name, string $content = 'binary'): string
    {
        file_put_contents("{$this->dir}/{$name}", $content);

        return "{$this->dir}/{$name}";
    }

    private function email(): string
    {
        return implode("\r\n", [
            'From: =?UTF-8?Q?Ana_Pe=C3=B1a?= <ana@example.ph>',
            'To: Atty. Reyes <reyes@lawfirm.ph>, clerk@court.gov.ph',
            'Subject: =?UTF-8?Q?Demand_letter_=E2=80=93_Lot_12?=',
            'Date: Mon, 5 Oct 2026 09:30:00 +0800',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="b1"',
            '',
            '--b1',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Please see the attached demand for the unpaid balance.',
            '--b1',
            'Content-Type: application/pdf; name="demand.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode(str_repeat('PDFBYTES', 50)),
            '--b1--',
            '',
        ]);
    }

    public function test_an_email_is_read_as_headers_and_text_not_raw_mime(): void
    {
        $text = app(FileTextExtractor::class)->extract($this->file('demand.eml', $this->email()), 'eml');

        $this->assertStringContainsString('Subject: Demand letter – Lot 12', $text);
        $this->assertStringContainsString('From: Ana Peña <ana@example.ph>', $text);
        $this->assertStringContainsString('To: Atty. Reyes <reyes@lawfirm.ph>, clerk@court.gov.ph', $text);
        $this->assertStringContainsString('Please see the attached demand for the unpaid balance.', $text);
        $this->assertStringNotContainsString('UERGQllURV', $text, 'Encoded attachments are not indexed as text.');
    }

    public function test_old_word_excel_and_powerpoint_files_are_read_with_catdoc(): void
    {
        Process::fake([
            'catdoc *' => Process::result("DEED OF ABSOLUTE SALE\nKnow all men by these presents"),
            'xls2csv *' => Process::result("\"Date\",\"Amount\"\n\"2026-01-05\",\"15000\""),
            'catppt *' => Process::result('Case theory slide'),
        ]);
        $extractor = app(FileTextExtractor::class);

        $this->assertTrue($extractor->supports('DOC'));
        $this->assertStringContainsString('DEED OF ABSOLUTE SALE', $extractor->extract($this->file('deed.doc'), 'doc'));
        $this->assertStringContainsString('2026-01-05', $extractor->extract($this->file('ledger.xls'), 'xls'));
        $this->assertSame('Case theory slide', $extractor->extract($this->file('theory.ppt'), 'ppt'));

        Process::assertRan(fn (PendingProcess $p) => $p->command === ['catdoc', '-d', 'utf-8', '-w', "{$this->dir}/deed.doc"]);
    }

    public function test_an_outlook_message_is_converted_then_read_as_an_email(): void
    {
        $email = $this->email();
        Process::fake(function (PendingProcess $process) use ($email) {
            // msgconvert --outfile <eml> <msg>
            file_put_contents($process->command[2], $email);

            return Process::result();
        });

        $text = app(FileTextExtractor::class)->extract($this->file('demand.msg'), 'msg');

        $this->assertStringContainsString('Subject: Demand letter – Lot 12', $text);
        $this->assertStringContainsString('unpaid balance', $text);
        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'msgconvert' && $p->command[3] === "{$this->dir}/demand.msg");
    }

    public function test_without_the_tools_the_old_formats_are_unsupported_not_failed(): void
    {
        config(['services.text_extraction.tools' => ['catdoc' => false, 'xls2csv' => false, 'catppt' => false, 'msgconvert' => false]]);
        $extractor = app(FileTextExtractor::class);

        foreach (['doc', 'xls', 'ppt', 'msg'] as $ext) {
            $this->assertFalse($extractor->supports($ext));
        }
        $this->assertTrue($extractor->supports('docx'));
    }

    public function test_a_file_the_tool_cannot_read_is_an_error(): void
    {
        Process::fake(['catdoc *' => Process::result('', 'This file looks like ZIP archive', 2)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('catdoc could not read the file: This file looks like ZIP archive');
        app(FileTextExtractor::class)->extract($this->file('renamed.doc'), 'doc');
    }
}
