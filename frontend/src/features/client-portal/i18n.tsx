import { useQueryClient } from '@tanstack/react-query'
import { Languages } from 'lucide-react'
import { useId } from 'react'
import { Outlet } from 'react-router-dom'
import { put } from '@/shared/api/axios'
import { LOCALES, registerDictionary, setLocale, t, useLocale, usePortalLocaleScope, type Locale } from '@/shared/lib/i18n'

/**
 * Filipino for the client portal. Keys are the English text in the code.
 * Everyday English loanwords that clients use themselves (portal, email,
 * password, invoice, upload) are kept, as Filipino speakers write them.
 * Have a native-speaking lawyer review changes to legal wording.
 */
registerDictionary('fil', {
  // Shared
  'Loading…': 'Naglo-load…',
  'Try again': 'Subukang muli',
  Close: 'Isara',
  Cancel: 'Kanselahin',
  Confirm: 'Kumpirmahin',
  Language: 'Wika',

  // Sign-in and passwords
  'Client portal': 'Client portal',
  'Follow your cases, documents and account with your law firm.': 'Subaybayan ang iyong mga kaso, dokumento at account sa iyong law firm.',
  Email: 'Email',
  Password: 'Password',
  'Forgot password?': 'Nakalimutan ang password?',
  'Sign in': 'Mag-sign in',
  'No account? Your lawyer can give you portal access.': 'Wala pang account? Mabibigyan ka ng access ng iyong abogado.',
  'Firm staff?': 'Kawani ng firm?',
  'Sign in here': 'Mag-sign in dito',
  'Back to sign in': 'Bumalik sa pag-sign in',
  'Check your email': 'Tingnan ang iyong email',
  'The link expires in 60 minutes. Check your spam folder if it doesn’t arrive.': 'Mawawalan ng bisa ang link pagkalipas ng 60 minuto. Tingnan ang spam folder kung hindi ito dumating.',
  'If you are a client of more than one firm, you get a separate email for each.': 'Kung kliyente ka ng higit sa isang firm, may hiwalay na email para sa bawat isa.',
  'Reset your portal password': 'I-reset ang password mo sa portal',
  'We’ll email you a link to choose a new one.': 'Padadalhan ka namin ng link sa email para pumili ng bago.',
  'Send reset link': 'Ipadala ang link',
  'The passwords do not match.': 'Hindi magkatugma ang mga password.',
  'Link incomplete': 'Kulang ang link',
  'Open the link from your email again, or request a new one.': 'Buksan muli ang link mula sa iyong email, o humingi ng bago.',
  'Request a new link': 'Humingi ng bagong link',
  'You’re all set': 'Handa ka na',
  'Password changed': 'Napalitan na ang password',
  'Welcome! Choose your password': 'Maligayang pagdating! Pumili ng password',
  'Choose a new password': 'Pumili ng bagong password',
  'You’ll use it to sign in to your client portal.': 'Gagamitin mo ito sa pag-sign in sa iyong client portal.',
  'New password': 'Bagong password',
  'At least 12 characters, with upper- and lower-case letters and a number.': 'Hindi bababa sa 12 character, may malaki at maliit na titik at may numero.',
  'Confirm new password': 'Ulitin ang bagong password',
  'Set password': 'Itakda ang password',
  'Change password': 'Palitan ang password',

  // Portal shell
  Messages: 'Mga mensahe',
  'Messages, {count} unread': 'Mga mensahe, {count} hindi pa nababasa',
  'My data': 'Aking datos',
  'Sign out': 'Mag-sign out',
  'Questions? Contact {firm}': 'May tanong? Makipag-ugnayan sa {firm}',
  'at {phone}': 'sa {phone}',
  'or {email}': 'o sa {email}',
  'Information here is confidential and privileged.': 'Kumpidensyal at privileged ang impormasyong narito.',

  // Home
  'Welcome, {name}': 'Maligayang pagdating, {name}',
  'Here is where your matters stand today.': 'Narito ang kalagayan ng iyong mga kaso ngayon.',
  'Amount due': 'Halagang dapat bayaran',
  'An invoice is past due': 'May invoice na lampas na sa takdang petsa',
  'Held for you in trust': 'Hawak para sa iyo sa trust',
  'Deposits for fees and costs, held separately from the firm’s funds': 'Mga deposito para sa bayad at gastos, nakahiwalay sa pondo ng firm',
  'Your matters': 'Iyong mga kaso',
  'No matters yet': 'Wala pang kaso',
  'Stage:': 'Yugto:',
  '{title} progress': 'Usad ng {title}',
  'Next hearing:': 'Susunod na pagdinig:',
  'at {time}': 'nang {time}',
  'Your lawyer:': 'Iyong abogado:',
  Invoices: 'Mga invoice',
  'Pay by card, GCash, Maya or QR Ph through PayMongo.': 'Magbayad gamit ang card, GCash, Maya o QR Ph sa pamamagitan ng PayMongo.',
  'No invoices': 'Walang invoice',
  Number: 'Numero',
  Due: 'Takdang petsa',
  Status: 'Katayuan',
  Amount: 'Halaga',
  Paid: 'Bayad na',
  Overdue: 'Lampas na sa takdang petsa',
  'Partly paid': 'Bahagyang bayad',
  Unpaid: 'Hindi pa bayad',
  Balance: 'Natitira',
  'Pay {amount}': 'Bayaran ang {amount}',
  Pay: 'Magbayad',
  'Trust account activity': 'Galaw ng trust account',
  'No trust deposits': 'Walang deposito sa trust',
  'Waiting for your signature': 'Naghihintay ng iyong pirma',
  'Review each document and sign it online.': 'Basahin ang bawat dokumento at pirmahan ito online.',
  'from {name}': 'mula kay {name}',
  'respond by {date}': 'sumagot bago ang {date}',
  'Review and sign': 'Basahin at pirmahan',
  'Payment was cancelled. Nothing was charged.': 'Kinansela ang pagbabayad. Walang siningil.',
  'Payment received. Thank you!': 'Natanggap ang bayad. Salamat!',
  'Thank you. We’re confirming your payment with PayMongo; this usually takes a few seconds.': 'Salamat. Kinukumpirma namin ang iyong bayad sa PayMongo; karaniwang ilang segundo lang ito.',
  Dismiss: 'Isara',

  // A matter
  'All matters': 'Lahat ng kaso',
  'Current stage:': 'Kasalukuyang yugto:',
  'Case progress': 'Usad ng kaso',
  Details: 'Mga detalye',
  Court: 'Hukuman',
  'Your lawyer': 'Iyong abogado',
  Opened: 'Binuksan',
  'Next hearing': 'Susunod na pagdinig',
  'None scheduled': 'Wala pang nakatakda',
  'Progress so far': 'Usad sa ngayon',
  'Shared documents': 'Mga ibinahaging dokumento',
  'No documents shared yet': 'Wala pang ibinahaging dokumento',
  Files: 'Mga file',
  'Copies the firm has shared with you.': 'Mga kopyang ibinahagi sa iyo ng firm.',
  Document: 'Dokumento',
  'Budget:': 'Badyet:',
  '{used} of {total} used': '{used} sa {total} ang nagamit',
  'Budget used': 'Nagamit sa badyet',
  'Hours of work on your matter, as agreed with your lawyer.': 'Mga oras ng trabaho sa iyong kaso, ayon sa napagkasunduan ninyo ng iyong abogado.',
  'Professional fees and expenses, as agreed with your lawyer.': 'Propesyonal na bayad at mga gastos, ayon sa napagkasunduan ninyo ng iyong abogado.',
  'Professional fees, as agreed with your lawyer.': 'Propesyonal na bayad, ayon sa napagkasunduan ninyo ng iyong abogado.',

  // Signing
  'Back to your matters': 'Bumalik sa iyong mga kaso',
  'Signed. Thank you.': 'Napirmahan na. Salamat.',
  'You declined to sign this document.': 'Tumanggi kang pirmahan ang dokumentong ito.',
  'This request has expired.': 'Nag-expire na ang kahilingang ito.',
  'This request was cancelled.': 'Kinansela ang kahilingang ito.',
  'Your lawyer has been notified. A copy stays available under your matter.': 'Naabisuhan na ang iyong abogado. May kopyang mananatili sa iyong kaso.',
  'Please contact your lawyer if you have questions.': 'Makipag-ugnayan sa iyong abogado kung may tanong ka.',
  Done: 'Tapos',
  'Review and sign: {title}': 'Basahin at pirmahan: {title}',
  'Requested by {name}': 'Hiniling ni {name}',
  'Please respond by {date}': 'Pakisagot bago ang {date}',
  'Read the whole document before signing.': 'Basahin ang buong dokumento bago pumirma.',
  'Document text': 'Teksto ng dokumento',
  'Document fingerprint (SHA-256):': 'Fingerprint ng dokumento (SHA-256):',
  'Your signature': 'Iyong pirma',
  'Full name of the person signing': 'Buong pangalan ng pumipirma',
  'Signing for a company? Enter your own name; your lawyer has your authority on file.': 'Pumipirma para sa isang kumpanya? Ilagay ang sarili mong pangalan; nasa abogado mo ang iyong awtorisasyon.',
  'How to sign': 'Paraan ng pagpirma',
  Draw: 'Iguhit',
  Type: 'I-type',
  'Signature drawing area': 'Lugar para iguhit ang pirma',
  'Typed signature preview': 'Silip sa na-type na pirma',
  'Your name': 'Iyong pangalan',
  'I have read this document, and I agree to sign it electronically. My electronic signature has the same effect as my handwritten signature.':
    'Nabasa ko ang dokumentong ito, at pumapayag akong pirmahan ito nang elektroniko. Ang aking elektronikong pirma ay may parehong bisa ng sulat-kamay kong pirma.',
  'Sign document': 'Pirmahan ang dokumento',
  'Decline to sign': 'Tumangging pumirma',
  'We record the time, your IP address and browser with your signature, as evidence under the E-Commerce Act (RA 8792).':
    'Itinatala namin ang oras, ang iyong IP address at browser kasama ng iyong pirma, bilang ebidensya sa ilalim ng E-Commerce Act (RA 8792).',
  'Decline to sign?': 'Tatanggi kang pumirma?',
  'Your lawyer will be told. You can tell them why below.': 'Sasabihan ang iyong abogado. Maaari mong isulat sa ibaba ang dahilan.',
  Decline: 'Tumanggi',
  'Reason (optional)': 'Dahilan (opsyonal)',
  Clear: 'Burahin',

  // Privacy
  'How {firm} handles your information': 'Paano pinangangasiwaan ng {firm} ang iyong impormasyon',
  'Our privacy notice has changed. Please read the new version.': 'Nagbago ang aming privacy notice. Pakibasa ang bagong bersyon.',
  'Before you continue, please read our privacy notice.': 'Bago magpatuloy, pakibasa ang aming privacy notice.',
  'Privacy notice': 'Privacy notice',
  'our Data Protection Officer': 'ang aming Data Protection Officer',
  'I have read this notice': 'Nabasa ko na ang abisong ito',
  'Request sent. We will answer within 15 days.': 'Naipadala ang kahilingan. Sasagot kami sa loob ng 15 araw.',
  'Under the Data Privacy Act you may see, correct or delete the information we hold about you, object to how we use it, or get a copy to take elsewhere.':
    'Sa ilalim ng Data Privacy Act, maaari mong tingnan, itama o ipabura ang impormasyong hawak namin tungkol sa iyo, tutulan kung paano namin ito ginagamit, o kumuha ng kopya na madadala mo sa iba.',
  'Make a request': 'Gumawa ng kahilingan',
  'I would like to': 'Nais kong',
  'We may need to keep some records while a case is open or the law requires it; we will explain.': 'Maaaring kailangan naming itago ang ilang rekord habang bukas ang kaso o kung hinihingi ng batas; ipaliliwanag namin.',
  'What should we correct?': 'Ano ang dapat naming itama?',
  'Anything we should know (optional)': 'May dapat ba kaming malaman? (opsyonal)',
  'Send request': 'Ipadala ang kahilingan',
  'Your requests': 'Iyong mga kahilingan',
  'No requests yet': 'Wala pang kahilingan',
  'Answer by {date}': 'Sasagutin bago ang {date}',
  Declined: 'Tinanggihan',
  'Sent {date}': 'Ipinadala noong {date}',
  'Our answer:': 'Aming sagot:',
  'Version {version}. You read it on {date}.': 'Bersyon {version}. Nabasa mo ito noong {date}.',
  'Version {version}.': 'Bersyon {version}.',

  // Document requests
  'Documents we need from you': 'Mga dokumentong kailangan namin mula sa iyo',
  'needed by {date}': 'kailangan bago ang {date}',
  '{count} still needed': '{count} pa ang kailangan',
  'all uploaded': 'na-upload na lahat',
  Upload: 'I-upload',
  View: 'Tingnan',
  Home: 'Home',
  '{done} of {total} done': '{done} sa {total} ang tapos',
  'Thank you, we have everything we asked for.': 'Salamat, natanggap na namin ang lahat ng hiningi namin.',
  'Files are checked for viruses and kept confidential with your matter. Clear phone photos are fine unless we asked for an original.':
    'Sinusuri ang mga file laban sa virus at itinatagong kumpidensyal kasama ng iyong kaso. Puwede ang malinaw na litrato mula sa phone maliban kung orihinal ang hiningi namin.',
  '{item}: received. Thank you.': '{item}: natanggap na. Salamat.',
  '(if available)': '(kung mayroon)',
  'Sent:': 'Naipadala:',
  'Upload {item}': 'I-upload ang {item}',
  'Upload again': 'I-upload muli',
  Needed: 'Kailangan',
  'Received, being checked': 'Natanggap, sinusuri pa',
  'Please upload again': 'Paki-upload muli',

  // The public consultation form (/consult/<firm>)
  'This page isn’t available': 'Hindi available ang pahinang ito',
  'The firm may not be accepting online requests. Please contact them directly.': 'Maaaring hindi tumatanggap ang firm ng online na kahilingan. Direktang makipag-ugnayan sa kanila.',
  'Request sent': 'Naipadala ang kahilingan',
  'A confirmation was sent to {email}.': 'Nagpadala kami ng kumpirmasyon sa {email}.',
  'Request a consultation': 'Humiling ng konsultasyon',
  'Tell us about your concern and when you are available. We will email you to confirm a schedule.': 'Ikuwento sa amin ang iyong concern at kung kailan ka available. Padadalhan ka namin ng email para kumpirmahin ang iskedyul.',
  'Full name': 'Buong pangalan',
  'I am': 'Ako ay',
  'An individual': 'Isang indibidwal',
  'Representing a company': 'Kinatawan ng isang kumpanya',
  'Mobile number': 'Numero ng mobile',
  'Type of concern': 'Uri ng concern',
  'When did this happen or start?': 'Kailan ito nangyari o nagsimula?',
  'An approximate date is fine. Some claims must be filed within a set time.': 'Puwede ang tinatayang petsa. May mga claim na kailangang isampa sa loob ng takdang panahon.',
  'Briefly, what happened?': 'Sa maikli, ano ang nangyari?',
  'A short summary is enough. Please don’t include confidential documents yet.': 'Sapat na ang maikling buod. Huwag munang isama ang mga kumpidensyal na dokumento.',
  'Other people or companies involved': 'Ibang tao o kumpanyang sangkot',
  'For example the other party in a dispute. We check these names so we can tell you if we are able to help.': 'Halimbawa, ang kabilang partido sa isang alitan. Sinusuri namin ang mga pangalang ito upang masabi namin kung matutulungan ka namin.',
  'Other party {n}': 'Ibang partido {n}',
  Remove: 'Alisin',
  'Add another': 'Magdagdag pa',
  'When are you available? (Philippine time)': 'Kailan ka available? (oras sa Pilipinas)',
  'Preferred time {n}': 'Gustong oras {n}',
  'Add another time': 'Magdagdag ng isa pang oras',
  'I agree that {firm} may process the information above to respond to my request, as provided by the Data Privacy Act of 2012. I understand this does not yet create a lawyer-client relationship.':
    'Pumapayag ako na iproseso ng {firm} ang impormasyon sa itaas upang tumugon sa aking kahilingan, alinsunod sa Data Privacy Act of 2012. Nauunawaan ko na hindi pa ito lumilikha ng ugnayang abogado at kliyente.',
  'Read our privacy notice': 'Basahin ang aming privacy notice',

  // Messages
  'A private channel with your lawyers. Messages stay here, not in your email.': 'Pribadong daluyan ng usapan kasama ang iyong mga abogado. Dito nananatili ang mga mensahe, wala sa iyong email.',
  'New message': 'Bagong mensahe',
  'No messages yet': 'Wala pang mensahe',
  'Ask your lawyer a question about your matter.': 'Magtanong sa iyong abogado tungkol sa iyong kaso.',
  'Choose a conversation': 'Pumili ng usapan',
  'Back to messages': 'Bumalik sa mga mensahe',
  Send: 'Ipadala',
  'About which matter?': 'Tungkol sa aling kaso?',
  'Choose…': 'Pumili…',
  Subject: 'Paksa',
  Message: 'Mensahe',
  Attachment: 'Attachment',
  'Optional: a document or photo for your lawyer (up to 20 MB).': 'Opsyonal: dokumento o litrato para sa iyong abogado (hanggang 20 MB).',
  You: 'Ikaw',
  'Write a message': 'Sumulat ng mensahe',
  'Write a message…  (Ctrl+Enter to send)': 'Sumulat ng mensahe…  (Ctrl+Enter para ipadala)',
  Attach: 'Mag-attach',
  'Remove attachment': 'Alisin ang attachment',
})

/** Layout route around every portal page, signed in or not. */
export function PortalLocaleScope() {
  usePortalLocaleScope()
  // Re-mount the pages on a switch so every label and date is redrawn.
  return <Outlet key={useLocale()} />
}

/**
 * English / Filipino switch. Signed in, the choice is saved on the client's
 * record, so emails from the firm come in the same language.
 */
export function LanguageSwitcher({ signedIn, className }: { signedIn: boolean; className?: string }) {
  const locale = useLocale()
  const queryClient = useQueryClient()
  const id = useId()

  const change = (next: Locale) => {
    setLocale(next)
    if (signedIn) {
      // The server then answers in the new language; refetch what it labelled.
      void put('/portal/locale', { locale: next }).then(() => queryClient.invalidateQueries({ queryKey: ['portal'] }))
    }
  }

  return (
    <span className={className}>
      <label htmlFor={id} className="sr-only">{t('Language')}</label>
      <span className="relative inline-flex items-center">
        <Languages className="pointer-events-none absolute left-2 size-4" aria-hidden="true" />
        <select
          id={id}
          value={locale}
          onChange={(e) => change(e.target.value as Locale)}
          className="h-8 cursor-pointer appearance-none rounded-[3px] border border-current/30 bg-transparent pr-2 pl-7 text-sm text-current"
        >
          {LOCALES.map((l) => <option key={l.value} value={l.value} className="text-on-surface">{l.label}</option>)}
        </select>
      </span>
    </span>
  )
}
