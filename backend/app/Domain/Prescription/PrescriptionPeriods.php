<?php

namespace App\Domain\Prescription;

/**
 * Common prescriptive periods under Philippine law: how long after a cause
 * of action arises (or a crime is committed or discovered) the action must
 * be filed. A starting list for the firm's lawyers to check against the
 * law and jurisprudence for each case: special laws, the facts (when the
 * cause truly accrued), suspension and interruption all change the answer.
 *
 * `interruptible`: civil actions are interrupted by filing in court, a
 * written extrajudicial demand, or a written acknowledgment of the debt
 * (Civil Code, Art. 1155), after which the full period runs anew.
 */
final class PrescriptionPeriods
{
    public const GROUPS = [
        'civil' => 'Civil actions (Civil Code)',
        'labor' => 'Labor',
        'criminal' => 'Crimes (Revised Penal Code)',
        'special' => 'Special laws (Act No. 3326)',
        'other' => 'Other',
    ];

    /** @var array<string, array{group: string, label: string, years: int, months: int, basis: string, runs_from: string, interruptible: bool}> */
    public const PERIODS = [
        'written_contract' => ['group' => 'civil', 'label' => 'Action upon a written contract', 'years' => 10, 'months' => 0, 'basis' => 'Civil Code, Art. 1144(1)', 'runs_from' => 'the day the action accrues (e.g. the breach or the demand left unpaid)', 'interruptible' => true],
        'obligation_by_law' => ['group' => 'civil', 'label' => 'Action upon an obligation created by law', 'years' => 10, 'months' => 0, 'basis' => 'Civil Code, Art. 1144(2)', 'runs_from' => 'the day the action accrues', 'interruptible' => true],
        'judgment' => ['group' => 'civil', 'label' => 'Action upon a judgment', 'years' => 10, 'months' => 0, 'basis' => 'Civil Code, Art. 1144(3); see also Rule 39, Sec. 6 (execution by motion within 5 years)', 'runs_from' => 'the day the judgment became final', 'interruptible' => true],
        'mortgage' => ['group' => 'civil', 'label' => 'Mortgage action', 'years' => 10, 'months' => 0, 'basis' => 'Civil Code, Art. 1142', 'runs_from' => 'the day the action accrues', 'interruptible' => true],
        'real_action' => ['group' => 'civil', 'label' => 'Real action over immovables', 'years' => 30, 'months' => 0, 'basis' => 'Civil Code, Art. 1141', 'runs_from' => 'the day the action accrues', 'interruptible' => true],
        'movables' => ['group' => 'civil', 'label' => 'Action to recover movables', 'years' => 8, 'months' => 0, 'basis' => 'Civil Code, Art. 1140', 'runs_from' => 'the day possession was lost', 'interruptible' => true],
        'oral_contract' => ['group' => 'civil', 'label' => 'Action upon an oral contract', 'years' => 6, 'months' => 0, 'basis' => 'Civil Code, Art. 1145(1)', 'runs_from' => 'the day the action accrues', 'interruptible' => true],
        'quasi_contract' => ['group' => 'civil', 'label' => 'Action upon a quasi-contract', 'years' => 6, 'months' => 0, 'basis' => 'Civil Code, Art. 1145(2)', 'runs_from' => 'the day the action accrues', 'interruptible' => true],
        'injury_to_rights' => ['group' => 'civil', 'label' => 'Injury to the rights of the plaintiff', 'years' => 4, 'months' => 0, 'basis' => 'Civil Code, Art. 1146(1)', 'runs_from' => 'the day of the injury', 'interruptible' => true],
        'quasi_delict' => ['group' => 'civil', 'label' => 'Quasi-delict', 'years' => 4, 'months' => 0, 'basis' => 'Civil Code, Art. 1146(2)', 'runs_from' => 'the day of the act or omission', 'interruptible' => true],
        'annulment_of_contract' => ['group' => 'civil', 'label' => 'Annulment of a voidable contract', 'years' => 4, 'months' => 0, 'basis' => 'Civil Code, Art. 1391', 'runs_from' => 'the end of the intimidation, violence or undue influence; or discovery of the mistake or fraud', 'interruptible' => true],
        'rescission' => ['group' => 'civil', 'label' => 'Rescission of a rescissible contract', 'years' => 4, 'months' => 0, 'basis' => 'Civil Code, Art. 1389', 'runs_from' => 'the day the action accrues (for persons under guardianship, from the end of the incapacity)', 'interruptible' => true],
        'ejectment' => ['group' => 'civil', 'label' => 'Forcible entry or unlawful detainer', 'years' => 1, 'months' => 0, 'basis' => 'Civil Code, Art. 1147(1); Rule 70, Sec. 1', 'runs_from' => 'the unlawful deprivation (forcible entry) or the last demand to vacate (unlawful detainer)', 'interruptible' => true],
        'defamation_civil' => ['group' => 'civil', 'label' => 'Civil action for defamation', 'years' => 1, 'months' => 0, 'basis' => 'Civil Code, Art. 1147(2)', 'runs_from' => 'the publication', 'interruptible' => true],
        'other_civil' => ['group' => 'civil', 'label' => 'Other civil action with no fixed period', 'years' => 5, 'months' => 0, 'basis' => 'Civil Code, Art. 1149', 'runs_from' => 'the day the action accrues', 'interruptible' => true],

        'money_claims' => ['group' => 'labor', 'label' => 'Money claims from employment', 'years' => 3, 'months' => 0, 'basis' => 'Labor Code, Art. 306 [291]', 'runs_from' => 'the day the cause of action accrued (each unpaid amount on its own due date)', 'interruptible' => true],
        'illegal_dismissal' => ['group' => 'labor', 'label' => 'Illegal dismissal', 'years' => 4, 'months' => 0, 'basis' => 'Civil Code, Art. 1146 (injury to rights), as applied in Callanta v. Carnation Philippines (1986)', 'runs_from' => 'the day of the dismissal', 'interruptible' => true],

        'crime_20' => ['group' => 'criminal', 'label' => 'Crime punishable by reclusion perpetua or temporal', 'years' => 20, 'months' => 0, 'basis' => 'Revised Penal Code, Art. 90', 'runs_from' => 'the day the crime is discovered by the offended party, the authorities or their agents (Art. 91)', 'interruptible' => false],
        'crime_afflictive' => ['group' => 'criminal', 'label' => 'Other crime punishable by an afflictive penalty', 'years' => 15, 'months' => 0, 'basis' => 'Revised Penal Code, Art. 90', 'runs_from' => 'the day the crime is discovered (Art. 91)', 'interruptible' => false],
        'crime_correctional' => ['group' => 'criminal', 'label' => 'Crime punishable by a correctional penalty', 'years' => 10, 'months' => 0, 'basis' => 'Revised Penal Code, Art. 90', 'runs_from' => 'the day the crime is discovered (Art. 91)', 'interruptible' => false],
        'crime_arresto_mayor' => ['group' => 'criminal', 'label' => 'Crime punishable by arresto mayor', 'years' => 5, 'months' => 0, 'basis' => 'Revised Penal Code, Art. 90', 'runs_from' => 'the day the crime is discovered (Art. 91)', 'interruptible' => false],
        'libel' => ['group' => 'criminal', 'label' => 'Libel and similar offenses', 'years' => 1, 'months' => 0, 'basis' => 'Revised Penal Code, Art. 90', 'runs_from' => 'the day the crime is discovered (Art. 91)', 'interruptible' => false],
        'oral_defamation' => ['group' => 'criminal', 'label' => 'Oral defamation or slander by deed', 'years' => 0, 'months' => 6, 'basis' => 'Revised Penal Code, Art. 90', 'runs_from' => 'the day the crime is discovered (Art. 91)', 'interruptible' => false],
        'light_offense' => ['group' => 'criminal', 'label' => 'Light offense', 'years' => 0, 'months' => 2, 'basis' => 'Revised Penal Code, Art. 90', 'runs_from' => 'the day the crime is discovered (Art. 91)', 'interruptible' => false],

        'special_fine_or_1_month' => ['group' => 'special', 'label' => 'Special law: fine only, or imprisonment up to 1 month', 'years' => 1, 'months' => 0, 'basis' => 'Act No. 3326, Sec. 1', 'runs_from' => 'the commission of the violation, or its discovery if not known then (Sec. 2)', 'interruptible' => false],
        'special_up_to_2_years' => ['group' => 'special', 'label' => 'Special law: imprisonment over 1 month, under 2 years (e.g. B.P. 22)', 'years' => 4, 'months' => 0, 'basis' => 'Act No. 3326, Sec. 1', 'runs_from' => 'the commission of the violation, or its discovery (Sec. 2); for B.P. 22, the end of the 5 banking days after notice of dishonor', 'interruptible' => false],
        'special_2_to_6_years' => ['group' => 'special', 'label' => 'Special law: imprisonment of 2 to 6 years', 'years' => 8, 'months' => 0, 'basis' => 'Act No. 3326, Sec. 1', 'runs_from' => 'the commission of the violation, or its discovery (Sec. 2)', 'interruptible' => false],
        'special_6_years_or_more' => ['group' => 'special', 'label' => 'Special law: imprisonment of 6 years or more', 'years' => 12, 'months' => 0, 'basis' => 'Act No. 3326, Sec. 1', 'runs_from' => 'the commission of the violation, or its discovery (Sec. 2)', 'interruptible' => false],

        'consumer_act' => ['group' => 'other', 'label' => 'Claim under the Consumer Act', 'years' => 2, 'months' => 0, 'basis' => 'R.A. 7394, Art. 169', 'runs_from' => 'the consummation of the transaction, or the deceptive or unfair act', 'interruptible' => false],
    ];

    /** @return array{group: string, label: string, years: int, months: int, basis: string, runs_from: string, interruptible: bool}|null */
    public static function find(string $key): ?array
    {
        return self::PERIODS[$key] ?? null;
    }
}
