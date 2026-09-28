<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_started_at')->nullable()->after('password');
            $table->string('subscription_status')->default('trial')->after('trial_started_at');
            $table->string('stripe_customer_id')->nullable()->index()->after('subscription_status');
            $table->string('stripe_subscription_id')->nullable()->index()->after('stripe_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_customer_id']);
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropColumn([
                'trial_started_at',
                'subscription_status',
                'stripe_customer_id',
                'stripe_subscription_id',
            ]);
        });
    }
};
