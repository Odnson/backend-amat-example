<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\LocationService;
use App\Services\MediaService;
use App\Services\ObservationService;
use App\Services\QualityAssessmentService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Services sebagai singleton
        $this->app->singleton(LocationService::class, function ($app) {
            return new LocationService();
        });

        $this->app->singleton(MediaService::class, function ($app) {
            return new MediaService();
        });

        $this->app->singleton(QualityAssessmentService::class, function ($app) {
            return new QualityAssessmentService();
        });

        $this->app->singleton(ObservationService::class, function ($app) {
            return new ObservationService(
                $app->make(LocationService::class),
                $app->make(MediaService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
