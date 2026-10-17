<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('governorate', 100)->nullable()->index();
        });

        Schema::table('inspection_reports', function (Blueprint $table) {
            $table->string('recommendation', 24)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
        });

        Schema::table('houses', function (Blueprint $table) {
            $table->foreignId('verification_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verification_decided_at')->nullable();
        });

        Schema::create('inspection_report_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_report_id')->constrained()->restrictOnDelete();
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('caption')->nullable();
            $table->timestamps();

            $table->index('inspection_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_report_photos');

        Schema::table('houses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verification_decided_by');
            $table->dropColumn('verification_decided_at');
        });

        Schema::table('inspection_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'recommendation',
                'completed_at',
                'reviewed_at',
                'review_notes',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['governorate']);
            $table->dropColumn('governorate');
        });
    }
};
