<?php

namespace App\Http\Controllers;

use App\AuditLogEntry;
use App\CarouselItem;
use App\CoreSettings;
use App\DiscordWebhook;
use App\Mail\EmailAnnouncementEmail;
use App\MeetingMinutes;
use App\News;
use App\StaffMember;
use App\User;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NewsController extends Controller
{
    public function index()
    {
        $articles = News::where('certification', false)->get()->sortByDesc('id');
        return view('dashboard.news.index', compact('articles'));
    }

    public function createArticle()
    {
        $staff = StaffMember::where('user_id', '!=', 1)->get();
        return view('dashboard.news.articles.create', compact('staff'));
    }

    public function minutesIndex()
    {
        $minutes = MeetingMinutes::all();

        return view('dashboard.news.meetingminutes', compact('minutes'));
    }

    public function minutesDelete($id)
    {
        $minutes = MeetingMinutes::whereId($id)->firstOrFail();
        AuditLogEntry::insert(Auth::user(), 'Deleted meeting minutes '.$minutes->title, User::find(1), 0);
        $minutes->delete();

        return redirect()->back()->with('info', 'Deleted item');
    }

    public function minutesUpload(Request $request)
    {
        $this->validate($request, [
            'title' => 'required',
            'file' => 'required',
        ]);

        $file = $request->file('file');

        $fileName = $file->getClientOriginalName();

        Storage::disk('local')->putFileAs(
            'public/files/minutes', $file, $fileName
        );

        $minutes = new MeetingMinutes([
            'user_id' => Auth::id(),
            'title' => $request->get('title'),
            'link' => Storage::url('public/files/minutes/'.$fileName),
        ]);

        $minutes->save();

        AuditLogEntry::insert(Auth::user(), 'Uploaded meeting minutes '.$minutes->title, User::find(1), 0);

        return redirect()->back()->with('success', 'Minutes uploaded!');
    }
}
