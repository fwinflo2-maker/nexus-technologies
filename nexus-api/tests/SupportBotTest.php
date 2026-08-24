<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Services\SupportBot;
use PHPUnit\Framework\TestCase;

if (!class_exists(SupportBot::class, false)) {
    $candidates = [
        __DIR__ . '/SupportBot.php',
        dirname(__DIR__) . '/src/Services/SupportBot.php',
        __DIR__ . '/../src/Services/SupportBot.php',
        dirname(__DIR__) . '/SupportBot.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            break;
        }
    }
}

final class SupportBotTest extends TestCase
{
    public function testGreetingContainsAssistantOrNexusAndDoesNotEscalate(): void
    {
        $r = SupportBot::reply('bonjour', [], ['lang' => 'fr']);
        $this->assertFalse($r['escalate']);
        $this->assertMatchesRegularExpression('/assistant|Nexus/i', (string) $r['reply']);
        $this->assertSame('greeting', $r['intent']);
    }

    public function testSendMoneyIsTransferAndMentionsEnvoyer(): void
    {
        $r = SupportBot::reply("je veux envoyer de l'argent", [], ['lang' => 'fr']);
        $this->assertSame('transfer', $r['intent']);
        $this->assertFalse($r['escalate']);
        $this->assertStringContainsString('Envoyer', (string) $r['reply']);
    }

    public function testStuckFollowUpAfterTransferTurn(): void
    {
        $history = [
            ['sender' => 'customer', 'body' => "je veux envoyer de l'argent"],
            ['sender' => 'bot', 'body' => 'Pour envoyer de l’argent, ouvrez Envoyer, puis le destinataire.'],
        ];
        $r = SupportBot::reply('mon transfert est bloqué', $history, ['lang' => 'fr']);
        $this->assertSame('transfer_stuck', $r['intent']);
        $this->assertFalse($r['escalate']);
        $this->assertNotSame('transfer', $r['intent']);
    }

    public function testGibberishAsksToClarifyWithoutEscalating(): void
    {
        $r = SupportBot::reply('asdfgh qwerty', [], ['lang' => 'fr']);
        $this->assertFalse($r['escalate']);
        $this->assertSame('unknown', $r['intent']);
        $this->assertMatchesRegularExpression('/comprendre|préciser|pas sûr/i', (string) $r['reply']);
    }

    public function testTwoUnknownsInARowEscalate(): void
    {
        $history = [
            ['sender' => 'customer', 'body' => 'asdfgh qwerty'],
            ['sender' => 'bot', 'body' => 'Je ne suis pas sûr de comprendre. Pouvez-vous préciser le sujet ?'],
        ];
        $r = SupportBot::reply('zzzz lmnop', $history, ['lang' => 'fr']);
        $this->assertTrue($r['escalate']);
        $this->assertNotNull($r['reply']);
        $this->assertNotSame('', $r['reply']);
    }

    public function testSpeakToAgentEscalates(): void
    {
        $r = SupportBot::reply('parler à un agent', [], ['lang' => 'fr']);
        $this->assertTrue($r['escalate']);
        $this->assertSame('human', $r['intent']);
        $this->assertNotNull($r['reply']);
    }

    public function testBalanceListsEurAmountFromContext(): void
    {
        $ctx = [
            'lang' => 'fr',
            'first_name' => 'Florent',
            'wallets' => [
                [
                    'currency' => 'EUR',
                    'available_balance' => 1250,
                    'pending_balance' => 0,
                    'in_transit_balance' => 0,
                    'balance' => 1250,
                ],
            ],
        ];
        $r = SupportBot::reply('quel est mon solde', [], $ctx);
        $this->assertSame('balance', $r['intent']);
        $this->assertFalse($r['escalate']);
        $this->assertStringContainsString('EUR', (string) $r['reply']);
        $this->assertStringContainsString('1 250,00', (string) $r['reply']);
    }

    public function testTelInsideIntellectuelDoesNotEscalateAsHuman(): void
    {
        $r = SupportBot::reply('je suis un intellectuel curieux', [], ['lang' => 'fr']);
        $this->assertFalse($r['escalate']);
        $this->assertNotSame('human', $r['intent']);
    }

    public function testPresetWidgetChipsResolveInFrench(): void
    {
        $chips = [
            "Je veux envoyer de l'argent" => 'transfer',
            'Question sur mon solde' => 'balance',
            'Vérification KYC' => 'kyc',
            'Mes frais' => 'fees',
            'Parler à un agent' => 'human',
            'Transferts' => 'transfer',
            'J’ai une autre question' => 'greeting',
        ];
        foreach ($chips as $label => $intent) {
            $r = SupportBot::reply($label, [], ['lang' => 'fr']);
            self::assertSame($intent, $r['intent'], $label);
            self::assertNotSame('', (string) $r['reply'], $label);
            if ($intent === 'human') {
                self::assertTrue($r['escalate'], $label);
            } else {
                self::assertFalse($r['escalate'], $label);
            }
        }
    }

    public function testPresetChipIdBypassesLanguage(): void
    {
        $r = SupportBot::reply('我想汇款', [], ['lang' => 'zh', 'chip' => 'transfer']);
        self::assertSame('transfer', $r['intent']);
        self::assertFalse($r['escalate']);
        self::assertNotSame('', (string) $r['reply']);
    }

    public function testSpanishAndChineseWelcomeLabelsResolve(): void
    {
        $r = SupportBot::reply('Quiero enviar dinero', [], ['lang' => 'es']);
        self::assertSame('transfer', $r['intent']);
        $zh = SupportBot::reply('我想汇款', [], ['lang' => 'zh']);
        self::assertSame('transfer', $zh['intent']);
        $fees = SupportBot::reply('Mis comisiones', [], ['lang' => 'es']);
        self::assertSame('fees', $fees['intent']);
    }

    public function testEnglishGreeting(): void
    {
        $r = SupportBot::reply('hello', [], ['lang' => 'en']);
        $this->assertFalse($r['escalate']);
        $this->assertSame('greeting', $r['intent']);
        $reply = (string) $r['reply'];
        $this->assertDoesNotMatchRegularExpression('/\\bBonjour\\b/', $reply);
        $this->assertMatchesRegularExpression('/hello|assistant|Nexus/i', $reply);
        $this->assertTrue(
            str_contains(strtolower($reply), 'hello')
            || str_contains(strtolower($reply), 'assistant')
            || str_contains($reply, 'Nexus')
        );
    }
}
