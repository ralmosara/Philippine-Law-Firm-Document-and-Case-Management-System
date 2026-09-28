<?php

namespace App\Domain\Corporate;

/**
 * Starting points for common corporate secretarial documents. Plain text
 * with {{ merge_fields }}: matter and client fields, the corporation's
 * profile (corp_*), and fields to fill in when generating (meeting details,
 * resolutions). Review each against the corporation's by-laws.
 */
class CorporateTemplates
{
    /** @return array<string, string> name => body */
    public static function all(): array
    {
        return [
            "Secretary's Certificate" => <<<'TXT'
                                    SECRETARY'S CERTIFICATE

I, {{ corp_secretary }}, of legal age, Filipino, with office address at {{ corp_principal_office }}, after having been duly sworn in accordance with law, depose and state that:

1. I am the duly elected Corporate Secretary of {{ client_name }} (the "Corporation"), a corporation duly organized and existing under Philippine law, with SEC Registration No. {{ corp_sec_reg_no }} and principal office at {{ corp_principal_office }};

2. At the meeting of the Board of Directors of the Corporation held on {{ meeting_date }} at {{ meeting_place }}, at which a quorum was present and acted throughout, the following resolution was unanimously approved and adopted:

   "{{ resolution_text }}"

3. The foregoing resolution has not been amended, modified or revoked and remains in full force and effect.

IN WITNESS WHEREOF, I have signed this certificate this ____ day of ______________ at ______________.


                                        _______________________
                                        {{ corp_secretary }}
                                        Corporate Secretary

SUBSCRIBED AND SWORN to before me this ____ day of ______________ at ______________, affiant exhibiting to me competent evidence of identity ______________.

Doc. No. ____;
Page No. ____;
Book No. ____;
Series of ____.
TXT,

            'Board Resolution' => <<<'TXT'
                        {{ client_name }}
                        SEC Registration No. {{ corp_sec_reg_no }}

                        BOARD RESOLUTION NO. {{ resolution_number }}
                        Series of {{ resolution_year }}

WHEREAS, {{ whereas_clause }};

NOW, THEREFORE, BE IT RESOLVED, as it is hereby resolved, that {{ resolution_text }};

RESOLVED FURTHER, that {{ authorized_officer }} be, as he or she hereby is, authorized to sign, execute and deliver any and all documents and to do all acts necessary to carry out the foregoing resolution.

APPROVED this {{ meeting_date }} at {{ meeting_place }}.

Certified correct:


_______________________
{{ corp_secretary }}
Corporate Secretary
TXT,

            'Notice of Annual Stockholders\' Meeting' => <<<'TXT'
                        {{ client_name }}

              NOTICE OF ANNUAL MEETING OF STOCKHOLDERS

To all stockholders:

Please be notified that the annual meeting of stockholders of {{ client_name }} will be held on {{ meeting_date }} at {{ meeting_time }} at {{ meeting_place }}, with the following agenda:

1. Call to order
2. Proof of notice and determination of quorum
3. Approval of the minutes of the previous meeting
4. Presentation and approval of the annual report and audited financial statements
5. Ratification of the acts of the board and officers
6. Election of directors for the ensuing year
7. Appointment of the external auditor
8. Other matters
9. Adjournment

Stockholders who cannot attend may be represented by proxy. Proxies must be submitted to the Corporate Secretary at {{ corp_principal_office }} before the meeting. Where allowed by the by-laws, stockholders may attend and vote by remote communication or in absentia.

{{ date_today }}


_______________________
{{ corp_secretary }}
Corporate Secretary
TXT,

            'Minutes of Annual Stockholders\' Meeting' => <<<'TXT'
                        {{ client_name }}
          MINUTES OF THE ANNUAL MEETING OF STOCKHOLDERS
                 Held on {{ meeting_date }} at {{ meeting_place }}

Present: stockholders owning or representing {{ shares_present }} shares, or {{ percent_present }}% of the outstanding capital stock.

1. CALL TO ORDER. The Chairman called the meeting to order at {{ meeting_time }} and presided. The Corporate Secretary recorded the minutes.

2. PROOF OF NOTICE AND QUORUM. The Corporate Secretary certified that notice was duly sent to all stockholders of record and that a quorum was present.

3. APPROVAL OF THE PREVIOUS MINUTES. On motion duly made and seconded, the minutes of the previous annual meeting were approved.

4. ANNUAL REPORT AND FINANCIAL STATEMENTS. The annual report and the audited financial statements for the fiscal year ended {{ fiscal_year_end_text }} were presented and, on motion duly made and seconded, approved.

5. RATIFICATION. All acts of the board of directors and officers during the past year were ratified.

6. ELECTION OF DIRECTORS. The following were elected directors to serve until their successors are elected and qualified: {{ directors_elected }}.

7. EXTERNAL AUDITOR. {{ external_auditor }} was appointed external auditor for the ensuing year.

8. ADJOURNMENT. There being no other business, the meeting was adjourned at {{ adjournment_time }}.


Attested by:                              Certified correct:

_______________________                   _______________________
Chairman                                  {{ corp_secretary }}
                                          Corporate Secretary
TXT,
        ];
    }
}
