<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('houses', function (Blueprint $table) {
            $table->decimal('public_listing_price_tnd', 10, 3)->nullable();
            $table->decimal('proposed_owner_rent_tnd', 10, 3)->nullable();
            $table->decimal('agreed_owner_rent_tnd', 10, 3)->nullable();
            $table->string('negotiation_status', 24)->nullable()->index();
            $table->text('negotiation_notes')->nullable();
            $table->timestamp('negotiation_last_contact_at')->nullable();
            $table->timestamp('price_agreed_at')->nullable();
            $table->foreignId('price_agreed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('houses')->whereNull('public_listing_price_tnd')->update([
            'public_listing_price_tnd' => DB::raw('monthly_rent_tnd'),
        ]);
    }

    public function down(): void
    {
        Schema::table('houses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_agreed_by');
            $table->dropIndex(['negotiation_status']);
            $table->dropColumn([
                'public_listing_price_tnd',
                'proposed_owner_rent_tnd',
                'agreed_owner_rent_tnd',
                'negotiation_status',
                'negotiation_notes',
                'negotiation_last_contact_at',
                'price_agreed_at',
            ]);
        });
    }
};
