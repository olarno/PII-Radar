<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Rule;

use App\Domain\DataFile;
use App\Domain\Finding\Severity;
use App\Domain\Flow\FlowOperation;
use App\Domain\Rule\ConcatenatedSqlRule;
use App\Domain\Rule\ExposedDataFileRule;
use App\Domain\Rule\HostJurisdictions;
use App\Domain\Rule\NoRetentionRule;
use App\Domain\Rule\OutsideQuebecTransferRule;
use App\Domain\Rule\PersonalDataInLogsRule;
use App\Domain\Rule\SensitiveDataInClearRule;
use App\Domain\Rule\UndeclaredThirdPartyRule;
use App\Domain\Rule\WeakPasswordStorageRule;
use App\Domain\SourceLocation;

final class RulesTest extends RuleTestCase
{
    public function testPii001FlagsHighlySensitiveColumnsButNotCredentials(): void
    {
        $findings = new SensitiveDataInClearRule()->evaluate(self::context());

        self::assertCount(1, $findings);
        self::assertSame(['clients.nas'], self::fieldsOf($findings));
        self::assertSame('schema.sql:5', (string) $findings[0]->location);
    }

    public function testPii001TrustsColumnsThatLookEncrypted(): void
    {
        self::assertSame([], new SensitiveDataInClearRule()->evaluate(self::context([], [], 'nas_chiffre')));
    }

    public function testPii002KeepsOnlyPersonalDataAndRatesBySensitivity(): void
    {
        $rule = new PersonalDataInLogsRule();

        $high = $rule->evaluate(self::context([self::flow(FlowOperation::Log, ['id', 'nas', 'nom'], 'error_log')]));
        self::assertSame(['clients.nas', 'clients.nom'], self::fieldsOf($high));
        self::assertSame(Severity::High, $high[0]->severity);

        $medium = $rule->evaluate(self::context([self::flow(FlowOperation::Log, ['courriel'], 'logger')]));
        self::assertSame(Severity::Medium, $medium[0]->severity);

        self::assertSame([], $rule->evaluate(self::context([self::flow(FlowOperation::Log, ['id', 'email_template'])])), 'Low-confidence candidates do not raise findings.');
    }

    public function testPii003IgnoresMailToTheDataSubjectAndDeclaredRecipients(): void
    {
        $flows = [
            self::flow(FlowOperation::Send, ['courriel', 'nom'], 'api.example-crm.com', line: 1),
            self::flow(FlowOperation::Send, ['courriel'], 'mail:clients.courriel', line: 2),
            self::flow(FlowOperation::Send, ['courriel'], 'api.declared.com', line: 3),
        ];
        $findings = new UndeclaredThirdPartyRule(['api.declared.com'])->evaluate(self::context($flows));

        self::assertCount(1, $findings);
        self::assertSame('api.example-crm.com', $findings[0]->recipient);
    }

    public function testPii004FlagsConcatenatedPersonalData(): void
    {
        $rule = new ConcatenatedSqlRule();

        $findings = $rule->evaluate(self::context([self::flow(FlowOperation::Read, ['nom'], 'clients', ['courriel', 'id'])]));
        self::assertSame(['clients.courriel'], self::fieldsOf($findings));
        self::assertSame(Severity::High, $findings[0]->severity);

        $low = $rule->evaluate(self::context([self::flow(FlowOperation::Store, ['derniere_ip'], 'clients', ['derniere_ip'])]));
        self::assertSame(Severity::Medium, $low[0]->severity);

        self::assertSame([], $rule->evaluate(self::context([self::flow(FlowOperation::Read, ['nom'], 'clients', ['id'])])));
    }

    public function testPii005FlagsWeakHashesAndPlaintextPasswords(): void
    {
        $rule = new WeakPasswordStorageRule();

        $md5 = $rule->evaluate(self::context([self::flow(FlowOperation::Hash, ['mot_de_passe'], 'md5')]));
        self::assertSame(Severity::Critical, $md5[0]->severity);
        self::assertStringContainsString('md5', $md5[0]->summary);

        self::assertSame([], $rule->evaluate(self::context([
            self::flow(FlowOperation::Hash, ['mot_de_passe'], 'password_hash'),
            self::flow(FlowOperation::Store, ['mot_de_passe'], 'clients'),
        ])), 'password_hash is fine, and the stored value is the hash.');

        $plaintext = $rule->evaluate(self::context([self::flow(FlowOperation::Store, ['mot_de_passe', 'nom'], 'clients')]));
        self::assertSame(['clients.mot_de_passe'], self::fieldsOf($plaintext));

        self::assertSame([], $rule->evaluate(self::context([self::flow(FlowOperation::Hash, ['courriel'], 'md5')])), 'Hashing an e-mail (Gravatar) is not password storage.');
    }

    public function testPii006FlagsTablesWithoutDeletion(): void
    {
        $rule = new NoRetentionRule();

        $findings = $rule->evaluate(self::context());
        self::assertCount(1, $findings);
        self::assertSame('schema.sql:1', (string) $findings[0]->location);

        self::assertSame([], $rule->evaluate(self::context([self::flow(FlowOperation::Delete, ['id', 'nom'], 'clients')])));
        self::assertSame([], $rule->evaluate(self::context([], [], 'id', 'email_template')), 'A table without personal data needs no purge.');
    }

    public function testPii007FlagsExposedFilesAndNamesTheirPersonalColumns(): void
    {
        $files = [new DataFile(new SourceLocation('public/export.csv'), ['nom', 'courriel', 'total']), new DataFile(new SourceLocation('public/backup.sql'))];
        $findings = new ExposedDataFileRule()->evaluate(self::context([], $files));

        self::assertCount(2, $findings);
        self::assertSame(['clients.nom', 'clients.courriel'], self::fieldsOf($findings));
        self::assertSame(Severity::Critical, $findings[1]->severity);
        self::assertSame([], $findings[1]->fields);
    }

    public function testPii008FlagsTransfersOutsideQuebec(): void
    {
        $flows = [
            self::flow(FlowOperation::Send, ['courriel'], 'api.twilio.com', line: 1),
            self::flow(FlowOperation::Send, ['courriel'], 'www.ramq.gouv.qc.ca', line: 2),
            self::flow(FlowOperation::Send, ['courriel'], 'api.unknown-vendor.io', line: 3),
            self::flow(FlowOperation::Send, ['courriel'], 'mail:clients.courriel', line: 4),
        ];
        $findings = new OutsideQuebecTransferRule(new HostJurisdictions())->evaluate(self::context($flows));

        self::assertCount(2, $findings);
        self::assertSame(['US', Severity::Medium], [$findings[0]->jurisdiction, $findings[0]->severity]);
        self::assertSame([null, Severity::Low], [$findings[1]->jurisdiction, $findings[1]->severity]);
        self::assertStringContainsString('à confirmer', $findings[1]->summary);
    }
}
