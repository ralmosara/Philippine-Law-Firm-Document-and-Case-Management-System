<?php

/*
 * Filipino validation messages for the client portal's forms. Rules not
 * listed here fall back to English (the framework's own messages).
 */
return [
    'accepted' => 'Kailangang tanggapin ang :attribute.',
    'confirmed' => 'Hindi tugma ang pagkumpirma ng :attribute.',
    'email' => 'Dapat wastong email address ang :attribute.',
    'file' => 'Dapat file ang :attribute.',
    'in' => 'Hindi wasto ang napiling :attribute.',
    'integer' => 'Dapat buong numero ang :attribute.',
    'max' => [
        'file' => 'Hindi dapat lumampas sa :max kilobytes ang :attribute.',
        'string' => 'Hindi dapat lumampas sa :max character ang :attribute.',
        'numeric' => 'Hindi dapat lumampas sa :max ang :attribute.',
        'array' => 'Hindi dapat lumampas sa :max item ang :attribute.',
    ],
    'mimes' => 'Dapat isa sa mga uri ng file na ito ang :attribute: :values.',
    'min' => [
        'file' => 'Dapat hindi bababa sa :min kilobytes ang :attribute.',
        'string' => 'Dapat hindi bababa sa :min character ang :attribute.',
        'numeric' => 'Dapat hindi bababa sa :min ang :attribute.',
        'array' => 'Dapat may hindi bababa sa :min item ang :attribute.',
    ],
    'required' => 'Kailangan ang :attribute.',
    'required_if' => 'Kailangan ang :attribute.',
    'string' => 'Dapat teksto ang :attribute.',
    'uploaded' => 'Hindi na-upload ang :attribute.',

    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'details' => 'mga detalye',
        'subject' => 'paksa',
        'body' => 'mensahe',
        'file' => 'file',
        'matter_id' => 'kaso',
        'signer_name' => 'pangalan ng pumipirma',
        'reason' => 'dahilan',
        'type' => 'uri ng kahilingan',
        'locale' => 'wika',
        'name' => 'buong pangalan',
        'phone' => 'numero ng mobile',
        'client_type' => 'uri ng humihiling',
        'case_type' => 'uri ng concern',
        'description' => 'paglalarawan',
        'consent' => 'pahintulot',
        'incident_on' => 'petsa',
        'opposing_parties.*' => 'ibang partido',
        'preferred_times.*' => 'oras na gusto mo',
    ],
];
