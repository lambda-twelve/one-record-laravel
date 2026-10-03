<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract;

use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\Documents;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\Grant;

/**
 * Beyond the SDK's shipped AccessDelegationStoreContract (which runs against
 * the database store in tests/Contract/Sdk): grants come back in the order
 * they were made, with every detail intact. Run against the SDK's in-memory
 * store (the reference) and the database store.
 */
trait AccessDelegationStoreContract
{
    abstract protected function delegations(): AccessDelegationStore;

    public function testGrantsAreFoundPerAgentAndObjectInTheOrderTheyWereMade(): void
    {
        $partner = new Iri(Documents::PARTNER);
        $source = new Iri(Documents::BASE . '/action-requests/d1');
        $expires = Documents::at('2026-12-31T00:00:00.000Z');
        $this->delegations()->grant(new Grant($partner, Documents::iri('p1'), [Permission::GetLogisticsObject]));
        $this->delegations()->grant(new Grant($partner, Documents::iri('p1'), [Permission::PatchLogisticsObject, Permission::PostLogisticsEvent], $expires, $source));
        $this->delegations()->grant(new Grant(new Iri(Documents::OTHER), Documents::iri('p1'), [Permission::GetLogisticsObject]));
        $this->delegations()->grant(new Grant($partner, Documents::iri('p2'), [Permission::GetLogisticsEvent]));

        $grants = $this->delegations()->grantsFor($partner, Documents::iri('p1'));

        self::assertCount(2, $grants);
        self::assertSame([Permission::GetLogisticsObject], $grants[0]->permissions);
        self::assertNull($grants[0]->source);
        self::assertNull($grants[0]->expiresAt);
        self::assertSame([Permission::PatchLogisticsObject, Permission::PostLogisticsEvent], $grants[1]->permissions);
        self::assertSame($source->value, $grants[1]->source?->value);
        self::assertEquals($expires, $grants[1]->expiresAt);
        self::assertSame(Documents::PARTNER, $grants[1]->agent->value);
        self::assertSame(Documents::iri('p1')->value, $grants[1]->logisticsObject->value);
        self::assertTrue($grants[1]->isActiveAt(Documents::at('2026-12-30T00:00:00.000Z')));
        self::assertFalse($grants[1]->isActiveAt(Documents::at('2027-01-01T00:00:00.000Z')));
        self::assertTrue($grants[1]->allows(Permission::PostLogisticsEvent));
        self::assertFalse($grants[1]->allows(Permission::GetLogisticsObject));

        self::assertSame([], $this->delegations()->grantsFor($partner, Documents::iri('p3')));
        self::assertSame([], $this->delegations()->grantsFor(new Iri('https://nobody.example/x'), Documents::iri('p1')));
    }

}
