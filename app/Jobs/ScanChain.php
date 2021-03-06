<?php

namespace App\Jobs;

use Throwable;
use RuntimeException;
use Bugsnag\BugsnagLaravel\Facades\Bugsnag;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Cache\FileStore;
use App\Notifications\TimeslotsFound;
use App\Chain;
use App\Subscriber;
use App\Store;
use App\Timeslot;
use App\ScannerRun;
use Carbon\Carbon;

class ScanChain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const LOCK_DURATION_SECONDS = 600; // 10 minutes

    private $chain;

    public $scannerRun;

    private $storesCount = 0;
    private $timeslotsCount = 0;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Chain $chain)
    {
        $this->chain = $chain;

        $this->scannerRun = ScannerRun::create([
            'status' => 'ENQUEUED',
            'chain_id' => $this->chain->id
        ]);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $lock = null;

        try {
            $this->scannerRun->update([
                'status' => 'STARTED',
                'hostname' => gethostname()
            ]);

            $startTime = time();

            $cache = cache();

            if (app()->environment('local')) {
                info('Warning: Not acquiring lock for ' . __CLASS__);
                $this->scan();
            } else {
                $lock = $cache->lock(
                    __CLASS__ . $this->chain->id,
                    self::LOCK_DURATION_SECONDS
                );

                if ($lock->get()) {
                    $this->scan();
                } else {
                    throw new RuntimeException('Could not get lock for ' . __CLASS__ . ': ' . $this->chain->name);
                }
            }

            $this->scannerRun->update([
                'status' => 'SUCCEEDED',
                'duration_seconds' => time() - $startTime,
                'stores_scanned' => $this->storesCount,
                'timeslots_found' => $this->timeslotsCount,
            ]);

        } catch (Throwable $e) {
            Bugsnag::notifyException($e);

            $this->scannerRun->update([
                'status' => 'FAILED',
                'error_message' => $e->getMessage() . PHP_EOL . $e->getTraceAsString(),
                'duration_seconds' => time() - $startTime
            ]);
        } finally {
            optional($lock)->release();
        }
    }

    private function scan()
    {
        $storeScanner = $this->chain->getStoreScanner();

        $stores = $this->chain->stores()
            ->whereHas('subscribers', function (Builder $query) {
                $query->where('status', 'ACTIVE');
            })
            ->get();
        $this->storesCount = $stores->count();

        info('Scanning for timeslots at ' . $stores->count() . ' ' . $this->chain->name . ' stores');

        $timeslots = $storeScanner->scan($stores);
        $this->timeslotsCount = $timeslots->count();

        $subscribers = Subscriber::active()
            ->with('stores')
            ->get();

        $subscribers->each(function (Subscriber $subscriber) use ($timeslots) {
            $this->matchToTimeslots($subscriber, $timeslots);
        });
    }

    private function matchToTimeslots(Subscriber $subscriber, Collection $timeslots)
    {
        $subscribedStoreIds = $subscriber->stores()->pluck('stores.id')->toArray();

        $timeslots = $timeslots
            // Timeslots for stores that the user subscribed to
            ->filter(function ($timeslot) use ($subscribedStoreIds) {
                return in_array($timeslot->store_id, $subscribedStoreIds);
            })
            // Timeslots that are within the users set time criteria threshold
            ->filter(function ($timeslot) use ($subscriber) {
                return (
                    $subscriber->criteria === 'ANYTIME' ||
                    ($subscriber->criteria === 'SOON' && $timeslot->date->diffInDays() <= 3) ||
                    ($subscriber->criteria === 'TODAY' && $timeslot->date->isToday())
                );
            })
            ->sortBy(function ($timeslot) {
                return Carbon::parse(
                    $timeslot->date->format('Y-m-d') . ' ' .
                    Carbon::parse($timeslot->from)->format('H:i:s')
                );
            });

        if ($timeslots->count() > 0) {
            info('Found ' . $timeslots->count() . ' timeslot(s) for subscriber #' . $subscriber->id);

            $subscriber->status = 'PAUSED';
            $subscriber->save();

            info('Notifying subscriber #' . $subscriber->id);
            $subscriber->notify(new TimeslotsFound($timeslots));
        }
    }
}
