<?php

namespace Tests\Feature;

use App\Support\CredentialStore;
use Tests\TestCase;

/**
 * Sealed credential storage.
 *
 * The contract the MCP app surface depends on: plaintext goes in, ciphertext
 * goes to storage, and a value that cannot be opened is treated as absent
 * rather than fatal.
 */
final class CredentialStoreTest extends TestCase
{
    private function store(): CredentialStore
    {
        return app(CredentialStore::class);
    }

    public function testItRoundTripsEveryField(): void
    {
        $sealed = $this->store()->encrypt(array(
            'client_id' => 'abc',
            'client_secret' => 'shh',
        ));

        $this->assertSame(
            array('client_id' => 'abc', 'client_secret' => 'shh'),
            $this->store()->decrypt($sealed)
        );
    }

    public function testTheSealedPayloadNeverContainsThePlaintext(): void
    {
        $sealed = $this->store()->encrypt(array('client_secret' => 'shh'));

        $this->assertStringNotContainsString('shh', $sealed);
    }

    public function testBlankValuesAreSkipped(): void
    {
        // Blank means "keep the stored value", so it must not seal an empty one.
        $sealed = $this->store()->encrypt(array(
            'client_id' => 'abc',
            'client_secret' => '',
            'token' => '   ',
        ));

        $this->assertSame(array('client_id' => 'abc'), $this->store()->decrypt($sealed));
    }

    public function testAnEmptyMapSealsToAJsonObject(): void
    {
        // `{}`, not `[]` — the column is a map and a list would fail a cast.
        $this->assertSame('{}', $this->store()->encrypt(array()));
    }

    public function testAnUndecryptableValueIsDroppedAndTheRestSurvive(): void
    {
        $sealed = $this->store()->encrypt(array('a' => 'one', 'b' => 'two'));
        $map = (array) json_decode($sealed, true);

        $map['a'] = 'not-a-ciphertext';

        $opened = $this->store()->decrypt((string) json_encode($map));

        $this->assertArrayNotHasKey('a', $opened);
        $this->assertSame('two', $opened['b']);
    }

    public function testAValueSealedUnderAnotherKeyIsDropped(): void
    {
        // Simulates an APP_KEY rotation: the payload parses, nothing opens.
        $foreign = $this->store()->encrypt(array('a' => 'one'));
        $map = (array) json_decode($foreign, true);
        $map['a'] = substr((string) $map['a'], 0, -4).'AAAA';

        $this->assertSame(array(), $this->store()->decrypt((string) json_encode($map)));
    }

    public function testAnUnreadablePayloadOpensToNothing(): void
    {
        $this->assertSame(array(), $this->store()->decrypt('not json at all'));
        $this->assertSame(array(), $this->store()->decrypt(''));
        $this->assertSame(array(), $this->store()->decrypt(null));
    }

    public function testHasIsAPresenceCheck(): void
    {
        $this->assertFalse($this->store()->has(null));
        $this->assertFalse($this->store()->has($this->store()->encrypt(array())));
        $this->assertTrue($this->store()->has($this->store()->encrypt(array('token' => 'x'))));

        // Presence, not health: a sealed map whose values no longer open still
        // reports presence, which is why callers use decrypt() when the value
        // has to work.
        $this->assertTrue($this->store()->has('{"token":"garbage"}'));
    }

    public function testTheMaskIsUniformAndCarriesNothing(): void
    {
        $mask = $this->store()->mask($this->store()->encrypt(array('token' => 'x')));

        $this->assertSame("\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}", $mask);
        $this->assertStringNotContainsString('x', $mask);

        $this->assertSame('', $this->store()->mask(null));
        $this->assertSame('', $this->store()->mask($this->store()->encrypt(array())));
    }

    public function testTwoSealsOfTheSameValueDiffer(): void
    {
        // Laravel's Crypt is randomised; identical ciphertext would mean an ECB
        // style scheme, where equal secrets are visible as equal rows.
        $one = $this->store()->encrypt(array('token' => 'same'));
        $two = $this->store()->encrypt(array('token' => 'same'));

        $this->assertNotSame($one, $two);
    }
}
