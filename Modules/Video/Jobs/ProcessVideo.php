<?php

namespace Modules\Video\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Modules\Demo\App\Support\DemoSchemaManager;
use Modules\Video\Models\Video;
use Modules\Video\Support\VideoTranscoder;
use Throwable;

class ProcessVideo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;

    public function __construct(
        public int $videoId,
        public ?string $demoSchema = null,
        public ?string $demoUuid = null,
    ) {
        $this->timeout = (int) config('video.timeout', 1800);
    }

    public function handle(VideoTranscoder $transcoder, DemoSchemaManager $demoSchemaManager): void
    {
        if ($this->demoSchema && $this->demoSchema !== (string) config('demo.public_schema', 'public')) {
            $demoSchemaManager->activateForProcessing($this->demoSchema, $this->demoUuid);
        }

        $video = Video::query()->find($this->videoId);

        if (! $video || blank($video->upload_path)) {
            return;
        }

        $video->markAsProcessing();

        try {
            $video->markAsProcessed($transcoder->transcode($video));
        } catch (Throwable $exception) {
            report($exception);

            $video->markAsFailed(Str::limit(trim($exception->getMessage()), 500));
        }
    }
}
