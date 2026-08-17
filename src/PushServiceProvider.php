<?php

namespace Iamdevroyal\NativePush;

use Illuminate\Support\ServiceProvider;
use Iamdevroyal\NativePush\Commands\CopyFirebaseAssetsCommand;
use Iamdevroyal\NativePush\Commands\DispatchPushEventCommand;
use Iamdevroyal\NativePush\Server\FcmSender;

class PushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/push.php', 'push');

        $this->app->singleton(FcmSender::class, fn () => new FcmSender());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchPushEventCommand::class,
                CopyFirebaseAssetsCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/push.php' => config_path('push.php'),
            ], 'native-push-config');
        }
    }
}
