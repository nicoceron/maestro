<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TENANT_TABLES = [
        'service_categories',
        'services',
        'service_prices',
        'service_policies',
        'locations',
        'rooms',
        'equipment',
        'room_equipment',
        'program_offerings',
        'program_offering_overrides',
        'program_offering_staff',
        'staff_account_links',
        'staff_scheduling_profiles',
        'staff_availability_windows',
        'staff_availability_overrides',
        'staff_travel_buffers',
    ];

    public function up(): void
    {
        Schema::table('studio_memberships', function (Blueprint $table): void {
            $table->unique(['studio_id', 'id'], 'studio_memberships_studio_id_id_unique');
        });

        Schema::create('service_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->text('description')->nullable();
            $table->string('color', 7)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['studio_id', 'normalized_name']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'active', 'sort_order']);
        });

        Schema::create('services', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('service_category_id');
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('default_duration_minutes');
            $table->unsignedSmallInteger('default_capacity')->default(1);
            $table->unsignedBigInteger('default_price_minor')->default(0);
            $table->char('currency', 3);
            $table->unsignedInteger('booking_lead_minutes')->default(0);
            $table->unsignedInteger('cancellation_notice_minutes')->default(0);
            $table->string('makeup_policy', 24)->default('none');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'service_category_id'])
                ->references(['studio_id', 'id'])->on('service_categories')->restrictOnDelete();
            $table->unique(['studio_id', 'normalized_name']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'service_category_id', 'active']);
        });

        Schema::create('service_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('service_id');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'service_id'])
                ->references(['studio_id', 'id'])->on('services')->cascadeOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'service_id', 'effective_from'], 'service_prices_effective_index');
        });

        Schema::create('service_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('service_id');
            $table->unsignedInteger('booking_lead_minutes')->default(0);
            $table->unsignedInteger('cancellation_notice_minutes')->default(0);
            $table->string('makeup_policy', 24)->default('none');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'service_id'])
                ->references(['studio_id', 'id'])->on('services')->cascadeOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'service_id', 'effective_from'], 'service_policies_effective_index');
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->string('kind', 24)->default('physical');
            $table->string('timezone', 64);
            $table->string('address_line_1', 160)->nullable();
            $table->string('address_line_2', 160)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('online_url', 2048)->nullable();
            $table->text('private_instructions')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['studio_id', 'normalized_name']);
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'active']);
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('location_id');
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'location_id'])
                ->references(['studio_id', 'id'])->on('locations')->restrictOnDelete();
            $table->unique(['studio_id', 'location_id', 'normalized_name']);
            $table->unique(['studio_id', 'id']);
            $table->unique(['studio_id', 'id', 'location_id'], 'rooms_studio_id_location_unique');
            $table->index(['studio_id', 'location_id', 'active']);
        });

        Schema::create('equipment', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('location_id');
            $table->foreignUlid('room_id')->nullable();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'location_id'])
                ->references(['studio_id', 'id'])->on('locations')->restrictOnDelete();
            $table->foreign(['studio_id', 'room_id', 'location_id'], 'equipment_studio_room_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('rooms')->restrictOnDelete();
            $table->unique(['studio_id', 'location_id', 'normalized_name']);
            $table->unique(['studio_id', 'id']);
            $table->unique(['studio_id', 'id', 'location_id'], 'equipment_studio_id_location_unique');
            $table->index(['studio_id', 'room_id', 'active']);
        });

        Schema::create('room_equipment', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('location_id');
            $table->foreignUlid('room_id');
            $table->foreignUlid('equipment_id');
            $table->unsignedSmallInteger('quantity');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'room_id', 'location_id'], 'room_equipment_room_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('rooms')->cascadeOnDelete();
            $table->foreign(['studio_id', 'equipment_id', 'location_id'], 'room_equipment_equipment_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('equipment')->cascadeOnDelete();
            $table->unique(['studio_id', 'room_id', 'equipment_id']);
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('program_offerings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('service_id');
            $table->foreignUlid('location_id')->nullable();
            $table->foreignUlid('room_id')->nullable();
            $table->string('name', 140);
            $table->string('normalized_name', 140);
            $table->text('description')->nullable();
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('enrollment_open')->default(true);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'service_id'])
                ->references(['studio_id', 'id'])->on('services')->restrictOnDelete();
            $table->foreign(['studio_id', 'location_id'])
                ->references(['studio_id', 'id'])->on('locations')->restrictOnDelete();
            $table->foreign(['studio_id', 'room_id', 'location_id'], 'offerings_studio_room_location_fk')
                ->references(['studio_id', 'id', 'location_id'])->on('rooms')->restrictOnDelete();
            $table->unique(['studio_id', 'normalized_name']);
            $table->unique(['studio_id', 'id']);
            $table->unique(['studio_id', 'id', 'service_id'], 'program_offerings_studio_id_service_unique');
            $table->index(['studio_id', 'service_id', 'active']);
            $table->index(['studio_id', 'location_id', 'room_id']);
        });

        Schema::create('program_offering_staff', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('program_offering_id');
            $table->foreignUlid('staff_profile_id');
            $table->boolean('is_primary')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'program_offering_id'])
                ->references(['studio_id', 'id'])->on('program_offerings')->cascadeOnDelete();
            $table->foreign(['studio_id', 'staff_profile_id'])
                ->references(['studio_id', 'id'])->on('staff_profiles')->restrictOnDelete();
            $table->unique(['studio_id', 'program_offering_id', 'staff_profile_id'], 'program_offering_staff_unique');
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('program_offering_overrides', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('program_offering_id');
            $table->foreignUlid('staff_profile_id')->nullable();
            $table->foreignUlid('location_id')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedInteger('booking_lead_minutes')->nullable();
            $table->unsignedInteger('cancellation_notice_minutes')->nullable();
            $table->string('makeup_policy', 24)->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'program_offering_id'])
                ->references(['studio_id', 'id'])->on('program_offerings')->cascadeOnDelete();
            $table->foreign(['studio_id', 'staff_profile_id'])
                ->references(['studio_id', 'id'])->on('staff_profiles')->restrictOnDelete();
            $table->foreign(['studio_id', 'location_id'])
                ->references(['studio_id', 'id'])->on('locations')->restrictOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->index(
                ['studio_id', 'program_offering_id', 'staff_profile_id', 'location_id', 'effective_from'],
                'offering_overrides_resolution_index',
            );
        });

        Schema::create('staff_account_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('staff_profile_id');
            $table->foreignUlid('studio_membership_id');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'staff_profile_id'])
                ->references(['studio_id', 'id'])->on('staff_profiles')->cascadeOnDelete();
            $table->foreign(['studio_id', 'studio_membership_id'])
                ->references(['studio_id', 'id'])->on('studio_memberships')->cascadeOnDelete();
            $table->unique(['studio_id', 'staff_profile_id']);
            $table->unique(['studio_id', 'studio_membership_id']);
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('staff_scheduling_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('staff_profile_id');
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('default_buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('default_buffer_after_minutes')->default(0);
            $table->unsignedSmallInteger('default_travel_buffer_minutes')->default(0);
            $table->unsignedSmallInteger('max_daily_minutes')->nullable();
            $table->unsignedSmallInteger('max_weekly_minutes')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'staff_profile_id'])
                ->references(['studio_id', 'id'])->on('staff_profiles')->cascadeOnDelete();
            $table->unique(['studio_id', 'staff_profile_id']);
            $table->unique(['studio_id', 'id']);
        });

        Schema::create('staff_availability_windows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('staff_profile_id');
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('timezone', 64);
            $table->string('enforcement', 16)->default('hard');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'staff_profile_id'])
                ->references(['studio_id', 'id'])->on('staff_profiles')->cascadeOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->unique(
                ['studio_id', 'staff_profile_id', 'weekday', 'start_time', 'end_time'],
                'staff_availability_window_unique',
            );
            $table->index(['studio_id', 'staff_profile_id', 'weekday', 'active']);
        });

        Schema::create('staff_availability_overrides', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('staff_profile_id');
            $table->string('kind', 24);
            $table->string('approval_status', 24)->default('pending');
            $table->string('enforcement', 16)->default('hard');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('timezone', 64);
            $table->string('reason', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'staff_profile_id'])
                ->references(['studio_id', 'id'])->on('staff_profiles')->cascadeOnDelete();
            $table->unique(['studio_id', 'id']);
            $table->index(['studio_id', 'staff_profile_id', 'starts_at', 'ends_at'], 'staff_override_range_index');
        });

        Schema::create('staff_travel_buffers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('studio_id');
            $table->foreignUlid('staff_profile_id');
            $table->foreignUlid('from_location_id');
            $table->foreignUlid('to_location_id');
            $table->unsignedSmallInteger('minutes');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign(['studio_id', 'staff_profile_id'])
                ->references(['studio_id', 'id'])->on('staff_profiles')->cascadeOnDelete();
            $table->foreign(['studio_id', 'from_location_id'])
                ->references(['studio_id', 'id'])->on('locations')->restrictOnDelete();
            $table->foreign(['studio_id', 'to_location_id'])
                ->references(['studio_id', 'id'])->on('locations')->restrictOnDelete();
            $table->unique(
                ['studio_id', 'staff_profile_id', 'from_location_id', 'to_location_id'],
                'staff_travel_buffer_route_unique',
            );
            $table->unique(['studio_id', 'id']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX program_offering_staff_one_primary
            ON program_offering_staff (studio_id, program_offering_id)
            WHERE is_primary = true AND active = true
            SQL);

        $this->installDomainConstraints();
        $this->installOverlapGuards();
        $this->installRowLevelSecurity();
    }

    public function down(): void
    {
        $this->removeRowLevelSecurity();
        $this->removeOverlapGuards();

        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('studio_memberships', function (Blueprint $table): void {
            $table->dropUnique('studio_memberships_studio_id_id_unique');
        });
    }

    private function installDomainConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE service_categories ADD CONSTRAINT service_categories_state_check CHECK (version >= 1 AND length(trim(name)) > 0 AND (color IS NULL OR color ~ '^#[0-9A-F]{6}$'));
            ALTER TABLE services ADD CONSTRAINT services_state_check CHECK (
                version >= 1 AND length(trim(name)) > 0
                AND default_duration_minutes BETWEEN 5 AND 1440
                AND default_capacity BETWEEN 1 AND 1000
                AND default_price_minor BETWEEN 0 AND 999999999
                AND currency ~ '^[A-Z]{3}$'
                AND booking_lead_minutes <= 525600
                AND cancellation_notice_minutes <= 525600
                AND makeup_policy IN ('none', 'studio_credit', 'reschedule')
            );
            ALTER TABLE service_prices ADD CONSTRAINT service_prices_state_check CHECK (version >= 1 AND amount_minor BETWEEN 0 AND 999999999 AND currency ~ '^[A-Z]{3}$' AND (effective_until IS NULL OR effective_until >= effective_from));
            ALTER TABLE service_policies ADD CONSTRAINT service_policies_state_check CHECK (version >= 1 AND booking_lead_minutes <= 525600 AND cancellation_notice_minutes <= 525600 AND makeup_policy IN ('none', 'studio_credit', 'reschedule') AND (effective_until IS NULL OR effective_until >= effective_from));
            ALTER TABLE locations ADD CONSTRAINT locations_state_check CHECK (version >= 1 AND length(trim(name)) > 0 AND kind IN ('physical', 'online', 'mobile') AND (country_code IS NULL OR country_code ~ '^[A-Z]{2}$') AND (kind = 'online' OR online_url IS NULL));
            ALTER TABLE rooms ADD CONSTRAINT rooms_state_check CHECK (version >= 1 AND length(trim(name)) > 0 AND capacity BETWEEN 1 AND 1000);
            ALTER TABLE equipment ADD CONSTRAINT equipment_state_check CHECK (version >= 1 AND length(trim(name)) > 0 AND quantity BETWEEN 1 AND 1000);
            ALTER TABLE room_equipment ADD CONSTRAINT room_equipment_state_check CHECK (version >= 1 AND quantity BETWEEN 1 AND 1000);
            ALTER TABLE program_offerings ADD CONSTRAINT program_offerings_state_check CHECK (
                version >= 1 AND length(trim(name)) > 0
                AND (duration_minutes IS NULL OR duration_minutes BETWEEN 5 AND 1440)
                AND (capacity IS NULL OR capacity BETWEEN 1 AND 1000)
                AND (price_minor IS NULL OR price_minor BETWEEN 0 AND 999999999)
                AND ((price_minor IS NULL AND currency IS NULL) OR (price_minor IS NOT NULL AND currency ~ '^[A-Z]{3}$'))
                AND (room_id IS NULL OR location_id IS NOT NULL)
                AND (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)
            );
            ALTER TABLE program_offering_overrides ADD CONSTRAINT program_offering_overrides_state_check CHECK (
                version >= 1
                AND (staff_profile_id IS NOT NULL OR location_id IS NOT NULL)
                AND (duration_minutes IS NULL OR duration_minutes BETWEEN 5 AND 1440)
                AND (capacity IS NULL OR capacity BETWEEN 1 AND 1000)
                AND (price_minor IS NULL OR price_minor BETWEEN 0 AND 999999999)
                AND ((price_minor IS NULL AND currency IS NULL) OR (price_minor IS NOT NULL AND currency ~ '^[A-Z]{3}$'))
                AND (booking_lead_minutes IS NULL OR booking_lead_minutes <= 525600)
                AND (cancellation_notice_minutes IS NULL OR cancellation_notice_minutes <= 525600)
                AND (makeup_policy IS NULL OR makeup_policy IN ('none', 'studio_credit', 'reschedule'))
                AND (effective_until IS NULL OR effective_until >= effective_from)
            );
            ALTER TABLE program_offering_staff ADD CONSTRAINT program_offering_staff_state_check CHECK (version >= 1);
            ALTER TABLE staff_account_links ADD CONSTRAINT staff_account_links_state_check CHECK (version >= 1);
            ALTER TABLE staff_scheduling_profiles ADD CONSTRAINT staff_scheduling_profiles_state_check CHECK (
                version >= 1
                AND default_buffer_before_minutes <= 240
                AND default_buffer_after_minutes <= 240
                AND default_travel_buffer_minutes <= 240
                AND (max_daily_minutes IS NULL OR max_daily_minutes BETWEEN 1 AND 1440)
                AND (max_weekly_minutes IS NULL OR max_weekly_minutes BETWEEN 1 AND 10080)
                AND (max_daily_minutes IS NULL OR max_weekly_minutes IS NULL OR max_weekly_minutes >= max_daily_minutes)
            );
            ALTER TABLE staff_availability_windows ADD CONSTRAINT staff_availability_windows_state_check CHECK (
                version >= 1 AND weekday BETWEEN 1 AND 7 AND start_time < end_time AND enforcement IN ('hard', 'soft')
            );
            ALTER TABLE staff_availability_overrides ADD CONSTRAINT staff_availability_overrides_state_check CHECK (
                version >= 1
                AND kind IN ('available', 'time_off')
                AND approval_status IN ('pending', 'approved', 'declined', 'canceled')
                AND enforcement IN ('hard', 'soft')
                AND (kind <> 'time_off' OR approval_status <> 'approved' OR enforcement = 'hard')
                AND starts_at < ends_at
            );
            ALTER TABLE staff_travel_buffers ADD CONSTRAINT staff_travel_buffers_state_check CHECK (
                version >= 1 AND minutes BETWEEN 0 AND 240 AND from_location_id <> to_location_id
            );
            SQL);
    }

    private function installOverlapGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.app_reject_overlapping_service_price()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                BEGIN
                    IF NEW.active AND EXISTS (
                        SELECT 1 FROM public.service_prices existing
                        WHERE existing.studio_id = NEW.studio_id
                            AND existing.service_id = NEW.service_id
                            AND existing.active
                            AND existing.id <> NEW.id
                            AND NEW.effective_from <= coalesce(existing.effective_until, 'infinity'::date)
                            AND coalesce(NEW.effective_until, 'infinity'::date) >= existing.effective_from
                    ) THEN
                        RAISE EXCEPTION 'service price effective periods may not overlap' USING ERRCODE = '23P01';
                    END IF;

                    RETURN NEW;
                END;
                $function$;

                CREATE TRIGGER service_prices_no_overlap
                BEFORE INSERT OR UPDATE ON service_prices
                FOR EACH ROW EXECUTE FUNCTION public.app_reject_overlapping_service_price();

                CREATE OR REPLACE FUNCTION public.app_reject_overlapping_service_policy()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                BEGIN
                    IF NEW.active AND EXISTS (
                        SELECT 1 FROM public.service_policies existing
                        WHERE existing.studio_id = NEW.studio_id
                            AND existing.service_id = NEW.service_id
                            AND existing.active
                            AND existing.id <> NEW.id
                            AND NEW.effective_from <= coalesce(existing.effective_until, 'infinity'::date)
                            AND coalesce(NEW.effective_until, 'infinity'::date) >= existing.effective_from
                    ) THEN
                        RAISE EXCEPTION 'service policy effective periods may not overlap' USING ERRCODE = '23P01';
                    END IF;

                    RETURN NEW;
                END;
                $function$;

                CREATE TRIGGER service_policies_no_overlap
                BEFORE INSERT OR UPDATE ON service_policies
                FOR EACH ROW EXECUTE FUNCTION public.app_reject_overlapping_service_policy();

                CREATE OR REPLACE FUNCTION public.app_reject_overlapping_offering_override()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                BEGIN
                    IF NEW.active AND EXISTS (
                        SELECT 1 FROM public.program_offering_overrides existing
                        WHERE existing.studio_id = NEW.studio_id
                            AND existing.program_offering_id = NEW.program_offering_id
                            AND existing.staff_profile_id IS NOT DISTINCT FROM NEW.staff_profile_id
                            AND existing.location_id IS NOT DISTINCT FROM NEW.location_id
                            AND existing.active
                            AND existing.id <> NEW.id
                            AND NEW.effective_from <= coalesce(existing.effective_until, 'infinity'::date)
                            AND coalesce(NEW.effective_until, 'infinity'::date) >= existing.effective_from
                    ) THEN
                        RAISE EXCEPTION 'program offering override effective periods may not overlap' USING ERRCODE = '23P01';
                    END IF;

                    RETURN NEW;
                END;
                $function$;

                CREATE TRIGGER program_offering_overrides_no_overlap
                BEFORE INSERT OR UPDATE ON program_offering_overrides
                FOR EACH ROW EXECUTE FUNCTION public.app_reject_overlapping_offering_override();

                CREATE OR REPLACE FUNCTION public.app_reject_overlapping_staff_availability()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                BEGIN
                    IF NEW.active AND EXISTS (
                        SELECT 1 FROM public.staff_availability_windows existing
                        WHERE existing.studio_id = NEW.studio_id
                            AND existing.staff_profile_id = NEW.staff_profile_id
                            AND existing.weekday = NEW.weekday
                            AND existing.active
                            AND existing.id <> NEW.id
                            AND NEW.start_time < existing.end_time
                            AND NEW.end_time > existing.start_time
                    ) THEN
                        RAISE EXCEPTION 'staff availability windows may not overlap' USING ERRCODE = '23P01';
                    END IF;

                    RETURN NEW;
                END;
                $function$;

                CREATE TRIGGER staff_availability_windows_no_overlap
                BEFORE INSERT OR UPDATE ON staff_availability_windows
                FOR EACH ROW EXECUTE FUNCTION public.app_reject_overlapping_staff_availability();

                CREATE OR REPLACE FUNCTION public.app_reject_overlapping_staff_override()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                BEGIN
                    IF NEW.active AND NEW.approval_status = 'approved' AND EXISTS (
                        SELECT 1 FROM public.staff_availability_overrides existing
                        WHERE existing.studio_id = NEW.studio_id
                            AND existing.staff_profile_id = NEW.staff_profile_id
                            AND existing.active
                            AND existing.approval_status = 'approved'
                            AND existing.kind = NEW.kind
                            AND existing.id <> NEW.id
                            AND NEW.starts_at < existing.ends_at
                            AND NEW.ends_at > existing.starts_at
                    ) THEN
                        RAISE EXCEPTION 'staff availability overrides may not overlap' USING ERRCODE = '23P01';
                    END IF;

                    RETURN NEW;
                END;
                $function$;

                CREATE TRIGGER staff_availability_overrides_no_overlap
                BEFORE INSERT OR UPDATE ON staff_availability_overrides
                FOR EACH ROW EXECUTE FUNCTION public.app_reject_overlapping_staff_override();

                CREATE OR REPLACE FUNCTION public.app_enforce_room_equipment_stock()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                DECLARE
                    available integer;
                    assigned integer;
                    equipment_location char(26);
                BEGIN
                    SELECT quantity, location_id INTO available, equipment_location
                    FROM public.equipment
                    WHERE studio_id = NEW.studio_id AND id = NEW.equipment_id
                    FOR UPDATE;

                    IF equipment_location IS DISTINCT FROM NEW.location_id THEN
                        RAISE EXCEPTION 'room equipment location mismatch' USING ERRCODE = '23514';
                    END IF;

                    SELECT coalesce(sum(quantity), 0) INTO assigned
                    FROM public.room_equipment
                    WHERE studio_id = NEW.studio_id
                        AND equipment_id = NEW.equipment_id
                        AND active
                        AND id <> NEW.id;

                    IF NEW.active AND assigned + NEW.quantity > available THEN
                        RAISE EXCEPTION 'room equipment assignments exceed stock' USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END;
                $function$;

                CREATE TRIGGER room_equipment_stock_guard
                BEFORE INSERT OR UPDATE ON room_equipment
                FOR EACH ROW EXECUTE FUNCTION public.app_enforce_room_equipment_stock();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER service_prices_no_overlap_insert
                BEFORE INSERT ON service_prices
                WHEN NEW.active = 1 AND EXISTS (
                    SELECT 1 FROM service_prices existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.service_id = NEW.service_id
                        AND existing.active = 1
                        AND date(NEW.effective_from) <= date(coalesce(existing.effective_until, '9999-12-31'))
                        AND date(coalesce(NEW.effective_until, '9999-12-31')) >= date(existing.effective_from)
                )
                BEGIN SELECT RAISE(ABORT, 'service price effective periods may not overlap'); END;

                CREATE TRIGGER service_prices_no_overlap_update
                BEFORE UPDATE ON service_prices
                WHEN NEW.active = 1 AND EXISTS (
                    SELECT 1 FROM service_prices existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.service_id = NEW.service_id
                        AND existing.active = 1
                        AND existing.id <> NEW.id
                        AND date(NEW.effective_from) <= date(coalesce(existing.effective_until, '9999-12-31'))
                        AND date(coalesce(NEW.effective_until, '9999-12-31')) >= date(existing.effective_from)
                )
                BEGIN SELECT RAISE(ABORT, 'service price effective periods may not overlap'); END;

                CREATE TRIGGER service_policies_no_overlap_insert
                BEFORE INSERT ON service_policies
                WHEN NEW.active = 1 AND EXISTS (
                    SELECT 1 FROM service_policies existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.service_id = NEW.service_id
                        AND existing.active = 1
                        AND date(NEW.effective_from) <= date(coalesce(existing.effective_until, '9999-12-31'))
                        AND date(coalesce(NEW.effective_until, '9999-12-31')) >= date(existing.effective_from)
                )
                BEGIN SELECT RAISE(ABORT, 'service policy effective periods may not overlap'); END;

                CREATE TRIGGER service_policies_no_overlap_update
                BEFORE UPDATE ON service_policies
                WHEN NEW.active = 1 AND EXISTS (
                    SELECT 1 FROM service_policies existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.service_id = NEW.service_id
                        AND existing.active = 1
                        AND existing.id <> NEW.id
                        AND date(NEW.effective_from) <= date(coalesce(existing.effective_until, '9999-12-31'))
                        AND date(coalesce(NEW.effective_until, '9999-12-31')) >= date(existing.effective_from)
                )
                BEGIN SELECT RAISE(ABORT, 'service policy effective periods may not overlap'); END;

                CREATE TRIGGER program_offering_overrides_no_overlap_insert
                BEFORE INSERT ON program_offering_overrides
                WHEN NEW.active = 1 AND EXISTS (
                    SELECT 1 FROM program_offering_overrides existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.program_offering_id = NEW.program_offering_id
                        AND existing.staff_profile_id IS NEW.staff_profile_id
                        AND existing.location_id IS NEW.location_id
                        AND existing.active = 1
                        AND date(NEW.effective_from) <= date(coalesce(existing.effective_until, '9999-12-31'))
                        AND date(coalesce(NEW.effective_until, '9999-12-31')) >= date(existing.effective_from)
                )
                BEGIN SELECT RAISE(ABORT, 'program offering override effective periods may not overlap'); END;

                CREATE TRIGGER program_offering_overrides_no_overlap_update
                BEFORE UPDATE ON program_offering_overrides
                WHEN NEW.active = 1 AND EXISTS (
                    SELECT 1 FROM program_offering_overrides existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.program_offering_id = NEW.program_offering_id
                        AND existing.staff_profile_id IS NEW.staff_profile_id
                        AND existing.location_id IS NEW.location_id
                        AND existing.active = 1
                        AND existing.id <> NEW.id
                        AND date(NEW.effective_from) <= date(coalesce(existing.effective_until, '9999-12-31'))
                        AND date(coalesce(NEW.effective_until, '9999-12-31')) >= date(existing.effective_from)
                )
                BEGIN SELECT RAISE(ABORT, 'program offering override effective periods may not overlap'); END;

                CREATE TRIGGER staff_availability_overrides_hard_time_off_insert
                BEFORE INSERT ON staff_availability_overrides
                WHEN NEW.kind = 'time_off' AND NEW.approval_status = 'approved' AND NEW.enforcement <> 'hard'
                BEGIN SELECT RAISE(ABORT, 'approved time off must use hard enforcement'); END;

                CREATE TRIGGER staff_availability_overrides_hard_time_off_update
                BEFORE UPDATE ON staff_availability_overrides
                WHEN NEW.kind = 'time_off' AND NEW.approval_status = 'approved' AND NEW.enforcement <> 'hard'
                BEGIN SELECT RAISE(ABORT, 'approved time off must use hard enforcement'); END;

                CREATE TRIGGER staff_availability_windows_no_overlap_insert
                BEFORE INSERT ON staff_availability_windows
                WHEN NEW.active = 1 AND EXISTS (
                    SELECT 1 FROM staff_availability_windows existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.staff_profile_id = NEW.staff_profile_id
                        AND existing.weekday = NEW.weekday
                        AND existing.active = 1
                        AND NEW.start_time < existing.end_time
                        AND NEW.end_time > existing.start_time
                )
                BEGIN SELECT RAISE(ABORT, 'staff availability windows may not overlap'); END;

                CREATE TRIGGER staff_availability_windows_no_overlap_update
                BEFORE UPDATE ON staff_availability_windows
                WHEN NEW.active = 1 AND EXISTS (
                    SELECT 1 FROM staff_availability_windows existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.staff_profile_id = NEW.staff_profile_id
                        AND existing.weekday = NEW.weekday
                        AND existing.active = 1
                        AND existing.id <> NEW.id
                        AND NEW.start_time < existing.end_time
                        AND NEW.end_time > existing.start_time
                )
                BEGIN SELECT RAISE(ABORT, 'staff availability windows may not overlap'); END;

                CREATE TRIGGER staff_availability_overrides_no_overlap_insert
                BEFORE INSERT ON staff_availability_overrides
                WHEN NEW.active = 1 AND NEW.approval_status = 'approved' AND EXISTS (
                    SELECT 1 FROM staff_availability_overrides existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.staff_profile_id = NEW.staff_profile_id
                        AND existing.active = 1
                        AND existing.approval_status = 'approved'
                        AND existing.kind = NEW.kind
                        AND datetime(NEW.starts_at) < datetime(existing.ends_at)
                        AND datetime(NEW.ends_at) > datetime(existing.starts_at)
                )
                BEGIN SELECT RAISE(ABORT, 'staff availability overrides may not overlap'); END;

                CREATE TRIGGER staff_availability_overrides_no_overlap_update
                BEFORE UPDATE ON staff_availability_overrides
                WHEN NEW.active = 1 AND NEW.approval_status = 'approved' AND EXISTS (
                    SELECT 1 FROM staff_availability_overrides existing
                    WHERE existing.studio_id = NEW.studio_id
                        AND existing.staff_profile_id = NEW.staff_profile_id
                        AND existing.active = 1
                        AND existing.approval_status = 'approved'
                        AND existing.kind = NEW.kind
                        AND existing.id <> NEW.id
                        AND datetime(NEW.starts_at) < datetime(existing.ends_at)
                        AND datetime(NEW.ends_at) > datetime(existing.starts_at)
                )
                BEGIN SELECT RAISE(ABORT, 'staff availability overrides may not overlap'); END;

                CREATE TRIGGER room_equipment_stock_guard_insert
                BEFORE INSERT ON room_equipment
                WHEN NEW.active = 1 AND (
                    (SELECT location_id FROM equipment WHERE studio_id = NEW.studio_id AND id = NEW.equipment_id) <> NEW.location_id
                    OR coalesce((SELECT sum(quantity) FROM room_equipment WHERE studio_id = NEW.studio_id AND equipment_id = NEW.equipment_id AND active = 1), 0) + NEW.quantity
                        > (SELECT quantity FROM equipment WHERE studio_id = NEW.studio_id AND id = NEW.equipment_id)
                )
                BEGIN SELECT RAISE(ABORT, 'room equipment assignments exceed stock'); END;

                CREATE TRIGGER room_equipment_stock_guard_update
                BEFORE UPDATE ON room_equipment
                WHEN NEW.active = 1 AND (
                    (SELECT location_id FROM equipment WHERE studio_id = NEW.studio_id AND id = NEW.equipment_id) <> NEW.location_id
                    OR coalesce((SELECT sum(quantity) FROM room_equipment WHERE studio_id = NEW.studio_id AND equipment_id = NEW.equipment_id AND active = 1 AND id <> NEW.id), 0) + NEW.quantity
                        > (SELECT quantity FROM equipment WHERE studio_id = NEW.studio_id AND id = NEW.equipment_id)
                )
                BEGIN SELECT RAISE(ABORT, 'room equipment assignments exceed stock'); END;
                SQL);
        }
    }

    private function installRowLevelSecurity(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY {$table}_studio_isolation ON {$table}
                    USING (studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26))
                    WITH CHECK (studio_id = nullif(current_setting('app.current_studio_id', true), '')::char(26));
                SQL);
        }
    }

    private function removeRowLevelSecurity(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            DB::unprepared(<<<SQL
                DROP POLICY IF EXISTS {$table}_studio_isolation ON {$table};
                ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY;
                ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;
                SQL);
        }
    }

    private function removeOverlapGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS program_offering_overrides_no_overlap ON program_offering_overrides;
                DROP FUNCTION IF EXISTS public.app_reject_overlapping_offering_override();
                DROP TRIGGER IF EXISTS service_policies_no_overlap ON service_policies;
                DROP FUNCTION IF EXISTS public.app_reject_overlapping_service_policy();
                DROP TRIGGER IF EXISTS service_prices_no_overlap ON service_prices;
                DROP FUNCTION IF EXISTS public.app_reject_overlapping_service_price();
                DROP TRIGGER IF EXISTS staff_availability_overrides_no_overlap ON staff_availability_overrides;
                DROP FUNCTION IF EXISTS public.app_reject_overlapping_staff_override();
                DROP TRIGGER IF EXISTS room_equipment_stock_guard ON room_equipment;
                DROP FUNCTION IF EXISTS public.app_enforce_room_equipment_stock();
                DROP TRIGGER IF EXISTS staff_availability_windows_no_overlap ON staff_availability_windows;
                DROP FUNCTION IF EXISTS public.app_reject_overlapping_staff_availability();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS staff_availability_overrides_hard_time_off_update;
                DROP TRIGGER IF EXISTS staff_availability_overrides_hard_time_off_insert;
                DROP TRIGGER IF EXISTS program_offering_overrides_no_overlap_update;
                DROP TRIGGER IF EXISTS program_offering_overrides_no_overlap_insert;
                DROP TRIGGER IF EXISTS service_policies_no_overlap_update;
                DROP TRIGGER IF EXISTS service_policies_no_overlap_insert;
                DROP TRIGGER IF EXISTS service_prices_no_overlap_update;
                DROP TRIGGER IF EXISTS service_prices_no_overlap_insert;
                DROP TRIGGER IF EXISTS staff_availability_overrides_no_overlap_update;
                DROP TRIGGER IF EXISTS staff_availability_overrides_no_overlap_insert;
                DROP TRIGGER IF EXISTS staff_availability_windows_no_overlap_update;
                DROP TRIGGER IF EXISTS staff_availability_windows_no_overlap_insert;
                DROP TRIGGER IF EXISTS room_equipment_stock_guard_update;
                DROP TRIGGER IF EXISTS room_equipment_stock_guard_insert;
                SQL);
        }
    }
};
