<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('maintenance_requests', 'category')) {
                $table->string('category', 32)->default('other');
            }
            if (! Schema::hasColumn('maintenance_requests', 'estimated_repair_cost_tnd')) {
                $table->decimal('estimated_repair_cost_tnd', 10, 3)->nullable();
            }
            if (! Schema::hasColumn('maintenance_requests', 'covered_by_service')) {
                $table->boolean('covered_by_service')->nullable();
            }
            if (! Schema::hasColumn('maintenance_requests', 'owner_received_at')) {
                $table->timestamp('owner_received_at')->nullable();
            }
            if (! Schema::hasColumn('maintenance_requests', 'owner_approved_at')) {
                $table->timestamp('owner_approved_at')->nullable();
            }
            if (! Schema::hasColumn('maintenance_requests', 'owner_confirmed_completed_at')) {
                $table->timestamp('owner_confirmed_completed_at')->nullable();
            }
            if (! Schema::hasColumn('maintenance_requests', 'owner_disputed_at')) {
                $table->timestamp('owner_disputed_at')->nullable();
            }
            if (! Schema::hasColumn('maintenance_requests', 'repair_outcome')) {
                $table->text('repair_outcome')->nullable();
            }
            if (! Schema::hasColumn('maintenance_requests', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
            $table->string('status', 40)->default('awaiting_owner_response')->change();
        });

        Schema::table('maintenance_request_photos', function (Blueprint $table) {
            if (! Schema::hasColumn('maintenance_request_photos', 'photo_type')) {
                $table->string('photo_type', 16)->default('reported');
            }
        });

        if (! Schema::hasTable('maintenance_request_updates')) {
            Schema::create('maintenance_request_updates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('maintenance_request_id')->constrained()->restrictOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_type', 32);
                $table->text('body')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasIndex('maintenance_request_updates', 'mru_request_created_idx')) {
            Schema::table('maintenance_request_updates', function (Blueprint $table) {
                $table->index(['maintenance_request_id', 'created_at'], 'mru_request_created_idx');
            });
        }

        if (! Schema::hasTable('maintenance_visits')) {
            Schema::create('maintenance_visits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('maintenance_request_id')->constrained()->restrictOnDelete();
                $table->foreignId('worker_id')->constrained('users')->restrictOnDelete();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('visited_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('maintenance_visits', 'mv_request_scheduled_idx')) {
            Schema::table('maintenance_visits', function (Blueprint $table) {
                $table->index(['maintenance_request_id', 'scheduled_at'], 'mv_request_scheduled_idx');
            });
        }
        if (! Schema::hasIndex('maintenance_visits', 'mv_worker_scheduled_idx')) {
            Schema::table('maintenance_visits', function (Blueprint $table) {
                $table->index(['worker_id', 'scheduled_at'], 'mv_worker_scheduled_idx');
            });
        }

        if (! Schema::hasTable('maintenance_notifications')) {
            Schema::create('maintenance_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('maintenance_request_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event_type', 32);
                $table->string('message', 500);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('maintenance_notifications', 'mn_recipient_read_created_idx')) {
            Schema::table('maintenance_notifications', function (Blueprint $table) {
                $table->index(['recipient_id', 'read_at', 'created_at'], 'mn_recipient_read_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_notifications');
        Schema::dropIfExists('maintenance_visits');
        Schema::dropIfExists('maintenance_request_updates');

        Schema::table('maintenance_request_photos', function (Blueprint $table) {
            $table->dropColumn('photo_type');
        });

        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropColumn([
                'category',
                'estimated_repair_cost_tnd',
                'covered_by_service',
                'owner_received_at',
                'owner_approved_at',
                'owner_confirmed_completed_at',
                'owner_disputed_at',
                'repair_outcome',
                'rejection_reason',
            ]);
            $table->string('status', 24)->default('open')->change();
        });
    }
};
