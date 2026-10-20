<?php

use App\Support\FileResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Models\AnnouncementFile;
use Modules\Mitteilungen\src\Services\AnnouncementService;

Route::prefix('mitteilungen')->name('mitteilungen.')
    ->middleware(['auth', 'active', 'module:mitteilungen'])
    ->group(function () {
        // All Mitteilungen for the Kamerad: current ones (read when shown), or the archive; filter "nur wichtige"
        Route::get('/', function (AnnouncementService $announcements) {
            $archive = request('ansicht') === 'archiv';
            $list = Announcement::for(auth()->user())->with(['author', 'files'])
                ->when($archive, fn ($q) => $q->where('visible_until', '<', now()), fn ($q) => $q->current())
                ->when(request()->boolean('wichtig'), fn ($q) => $q->where('important', true))
                ->orderByDesc('important')->latest()->get();
            if (! $archive) {
                $list->each(fn (Announcement $a) => $announcements->markRead($a, auth()->user()));
            }

            return view('mitteilungen::pages.index', [
                'announcements' => $list, 'archive' => $archive,
                'dismissed' => DB::table('announcement_dismissals')->where('user_id', auth()->id())->pluck('announcement_id')->all(),
                'acknowledged' => DB::table('announcement_reads')->where('user_id', auth()->id())->whereNotNull('acknowledged_at')->pluck('announcement_id')->all(),
            ]);
        })->name('index');

        // "Gelesen und verstanden"
        Route::post('/{announcement}/bestaetigen', function (Announcement $announcement, AnnouncementService $announcements) {
            abort_unless(Announcement::current()->for(auth()->user())->whereKey($announcement->id)->exists(), 403);
            $announcements->acknowledge($announcement, auth()->user());

            return back();
        })->whereNumber('announcement')->name('acknowledge');

        // An attachment: for whom the Mitteilung is meant, and the Redakteure
        Route::get('/anhang/{file}', function (AnnouncementFile $file, AnnouncementService $announcements) {
            $announcement = $file->announcement;
            $inPeriod = Announcement::for(auth()->user())->whereKey($announcement->id)
                ->where(fn ($q) => $q->current()->orWhere('visible_until', '<', now()))->exists();
            abort_unless($announcements->canManage(auth()->user(), $announcement) || $inPeriod, 403);
            $announcements->markRead($announcement, auth()->user());

            return FileResponse::make(base64_decode(AnnouncementFile::whereKey($file->id)->value('data')), $file->filename);
        })->whereNumber('file')->name('file');

        Route::get('/verwalten', fn () => view('mitteilungen::pages.manage'))->middleware('can:manage-announcements')->name('manage');

        // "Ausblenden" on the start page; a plain form, so it also works from the static card
        Route::post('/{announcement}/ausblenden', function (Announcement $announcement, AnnouncementService $announcements) {
            abort_unless(Announcement::current()->for(auth()->user())->whereKey($announcement->id)->exists(), 403);
            $announcements->dismiss($announcement, auth()->user());

            return back();
        })->whereNumber('announcement')->name('dismiss');

    });
