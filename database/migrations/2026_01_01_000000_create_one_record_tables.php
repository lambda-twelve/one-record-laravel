<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\Tables;

/*
 * Conventions: every IRI that is filtered or joined on has a *_hash column
 * (SHA-256 of the IRI) carrying the index, so matching is byte-exact on
 * every collation and composite keys stay short; the IRI itself is kept in
 * text next to it. Timestamps are UTC with microseconds. Documents are the
 * JSON-LD the SDK writes, in plain text (no JSON column type: nothing
 * queries inside them and MySQL's json type re-serialises).
 */
return new class extends Migration {
    public function getConnection(): ?string
    {
        $connection = config('one-record.storage.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        $tables = app(Tables::class);
        $schema = Schema::connection($this->getConnection());
        // Explicit index names: Laravel's generated ones exceed MySQL's 64-character limit.
        $p = $tables->prefix;

        // One row per logistics object: the compare-and-set target for new revisions.
        $schema->create($tables->objects(), static function (Blueprint $table) use ($p): void {
            $table->id();
            $table->char('iri_hash', 64)->unique($p . 'obj_iri_unique');
            $table->text('iri');
            $table->unsignedInteger('latest_revision');
            $table->dateTime('created_at', 6);   // when revision 1 was written
            $table->dateTime('updated_at', 6);   // when the latest revision was written
        });

        $schema->create($tables->revisions(), static function (Blueprint $table) use ($p): void {
            $table->id();
            $table->char('iri_hash', 64);
            $table->text('iri');
            $table->unsignedInteger('revision');
            $table->string('type', 255)->nullable();   // most specific class IRI, for operators
            $table->longText('document');
            $table->dateTime('created_at', 6);
            $table->unique(['iri_hash', 'revision'], $p . 'rev_iri_revision_unique');
            $table->index(['iri_hash', 'created_at'], $p . 'rev_iri_created_idx');
        });

        $schema->create($tables->events(), static function (Blueprint $table) use ($p): void {
            $table->id();
            $table->char('iri_hash', 64)->unique($p . 'evt_iri_unique');
            $table->text('iri');
            $table->char('logistics_object_hash', 64);
            $table->text('logistics_object_iri');
            $table->text('event_code')->nullable();
            $table->dateTime('event_date', 6)->nullable();
            $table->dateTime('creation_date', 6)->nullable();
            $table->dateTime('created_at', 6);   // receipt time
            $table->longText('document');
            $table->index(['logistics_object_hash', 'created_at'], $p . 'evt_object_created_idx');
            $table->index(['logistics_object_hash', 'event_date'], $p . 'evt_object_event_date_idx');
        });

        $schema->create($tables->actionRequests(), static function (Blueprint $table) use ($p): void {
            $table->id();
            $table->char('iri_hash', 64)->unique($p . 'ar_iri_unique');
            $table->text('iri');
            $table->string('type', 32);
            $table->string('status', 32);
            $table->char('requested_by_hash', 64);
            $table->text('requested_by');
            $table->dateTime('requested_at', 6);
            $table->dateTime('status_since', 6)->nullable();
            $table->dateTime('last_modified', 6);
            // Subscription projections, null for other request types.
            $table->string('topic_type', 32)->nullable();
            $table->text('topic')->nullable();
            $table->char('topic_hash', 64)->nullable();
            $table->char('subscriber_hash', 64)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->string('api_version', 8);
            $table->longText('document');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['type', 'status'], $p . 'ar_type_status_idx');
            $table->index(['topic_hash', 'type', 'status'], $p . 'ar_topic_idx');
            $table->index('requested_by_hash', $p . 'ar_requested_by_idx');
        });

        $schema->create($tables->actionRequestObjects(), static function (Blueprint $table) use ($p): void {
            $table->id();
            $table->char('action_request_hash', 64);
            $table->char('logistics_object_hash', 64);
            $table->text('logistics_object_iri');
            $table->unique(['action_request_hash', 'logistics_object_hash'], $p . 'aro_request_object_unique');
            $table->index('logistics_object_hash', $p . 'aro_object_idx');
        });

        $schema->create($tables->subscriptionOffers(), static function (Blueprint $table) use ($p): void {
            $table->id();
            $table->string('topic_type', 32);
            $table->text('topic');
            $table->char('topic_hash', 64);
            $table->longText('document');
            $table->dateTime('created_at', 6);
            $table->index(['topic_type', 'topic_hash'], $p . 'offer_topic_idx');
        });

        $schema->create($tables->grants(), static function (Blueprint $table) use ($p): void {
            $table->id();
            $table->char('agent_hash', 64);
            $table->text('agent');
            $table->char('logistics_object_hash', 64);
            $table->text('logistics_object_iri');
            $table->string('permissions', 255);
            $table->dateTime('expires_at', 6)->nullable();
            $table->char('source_hash', 64)->nullable();   // the access-delegation request; null = the holder's own grant
            $table->text('source')->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['agent_hash', 'logistics_object_hash'], $p . 'grant_agent_object_idx');
            $table->index('source_hash', $p . 'grant_source_idx');
            $table->index('logistics_object_hash', $p . 'grant_object_idx');
        });

        $schema->create($tables->outbox(), static function (Blueprint $table) use ($p): void {
            $table->id();
            // The SDK's own notification id, kept apart from the row key so the identity the
            // server assigned (and the Idempotency-Key it becomes) survives storage.
            $table->string('notification_id', 191);
            $table->char('recipient_hash', 64);
            $table->text('recipient');
            $table->text('endpoint')->nullable();
            $table->string('event_type', 64);
            $table->text('logistics_object')->nullable();
            $table->text('triggered_by')->nullable();
            $table->longText('document');
            $table->dateTime('created_at', 6);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at', 6)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();
            $table->dateTime('failed_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->index(['delivered_at', 'failed_at', 'next_attempt_at'], $p . 'outbox_due_idx');
            $table->index('recipient_hash', $p . 'outbox_recipient_idx');
            $table->unique('notification_id', $p . 'outbox_notification_unique');
        });

        $schema->create($tables->clients(), static function (Blueprint $table) use ($p): void {
            $table->id();
            $table->string('client_id', 191)->unique($p . 'client_id_unique');
            $table->string('secret_hash', 255);
            $table->text('agent_iri');
            $table->string('name', 255)->nullable();
            $table->boolean('enabled')->default(true);
            $table->dateTime('last_used_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (array_reverse(app(Tables::class)->all()) as $table) {
            $schema->dropIfExists($table);
        }
    }
};
