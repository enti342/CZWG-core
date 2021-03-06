<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\Settings\AuditLogEntry;
use App\Models\ControllerBookings\ControllerBookingsBan;
use App\Models\Users\DiscordBan;
use App\Notifications\DiscordLinkCreated;
use App\Notifications\DiscordWelcome;
use App\Notifications\PermissionsChanged;
use App\Models\Users\User;
use App\Models\Users\UserNote;
use App\Models\Users\UserNotification;
use App\Models\AtcTraining\RosterMember;
use App\Models\AtcTraining\VisitRosterMember;
use App\Notifications\WelcomeNewUser;
use Auth;
use Exception;
use Flash;
use RestCord\DiscordClient;
use SocialiteProviders\Manager\Config;
use function GuzzleHttp\Promise\all;
use function GuzzleHttp\Psr7\str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mail;
use mofodojodino\ProfanityFilter\Check;
use RestCord\Interfaces\AuditLog;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use NotificationChannels\Discord\Discord;


class UserController extends Controller
{
    public function privacyAccept()
    {
        $user = Auth::user();
        if ($user->init == 1) {
            return redirect()->route('index');
        }
        $user->init = 1;
        $user->save();
        $user->notify(new WelcomeNewUser($user));
        return redirect('/dashboard')->with('success', 'Welcome to Winnipeg, '.$user->fname.'! We are glad to have you on board.');
    }

    public function privacyDeny()
    {
        $user = Auth::user();
        if ($user->init == 1) {
            return redirect()->route('index');
        }
        Auth::logout($user);
        AuditLogEntry::insert(User::find(1), 'User '.$user->fullName('FLC').' denied privacy policy - account deleted', User::find(1), 0);
        $user->delete();
        return redirect()->route('index')->with('info', 'Your account has been removed as you have not accepted the privacy policy.');
    }

    public function viewAllUsers()
    {
        $users = User::all()->sortBy('id');

        return view('admin.users.index', compact('users'));
    }

    public function viewUserProfile($id)
    {
        $user = User::where('id', $id)->firstOrFail();
        $certification = null;
        $active = null;
        $potentialRosterMember = RosterMember::where('user_id', $user->id)->first();
        $potentialVisitRosterMember = VisitRosterMember::where('user_id', $user->id)->first();
        if ($potentialRosterMember !== null) {
          $certification = $potentialRosterMember->status;
          $active = $potentialRosterMember->active;
        } elseif ($potentialVisitRosterMember !== null) {
          $certification = $potentialVisitRosterMember->status;
          $active = $potentialVisitRosterMember->active;
        }
        

        $xml = [];
        $userNotes = UserNote::where('user_id', $user->id)->orderBy('timestamp', 'desc')->get();
        //$xml['return'] = file_get_contents('https://cert.vatsim.net/cert/vatsimnet/idstatus.php?cid=' . $user->id);
        $xml['return'] = 'sausage';
        $auditLog = AuditLogEntry::where('affected_id', $id)->get();

        return view('admin.users.profile', compact('user', 'xml', 'certification', 'active', 'auditLog', 'userNotes'));
    }

    public function editPermissions(Request $request,$id)
{
    $user = User::where('id', $id)->firstorFail();
    $roster = RosterMember::where('cid', $id)->first();
    $visitroster = VisitRosterMember::where('cid', $id)->first();
    $user->permissions = $request->input('permissions');
    $user->save();
    if($roster != null) {
    $roster->status = $request->input('certification');
    $roster->save();
  }
    if($visitroster != null) {
      $visitroster->status = $request->input('certification');
      $visitroster->save();

    }
      return redirect()->back()->withSuccess('User Permissions Changed!');
    }

    public function deleteUser($id)
    {
        $user = User::where('id', $id)->firstOrFail();
        if ($user->id == Auth::user()->id) {
            abort(403, 'You cannot delete yourself you fucking idiot.');
        }

        $entry = new AuditLogEntry([
            'user_id' => Auth::user()->id,
            'affected_id' => $user->id,
            'action' => 'DELETE USER',
            'time' => date('Y-m-d H:i:s'),
            'private' => 0,
        ]);
        $entry->save();
        $user->fname = 'Deleted';
        $user->lname = 'User';
        $user->email = 'no-reply@czqo.vatcan.ca';
        $user->rating = 'Deleted';
        $user->division = 'Deleted';
        $user->permissions = 0;
        $user->deleted = 1;
        $user->save();

        return redirect()->route('users.viewall')->with('info', 'User deleted.');
    }

    public function editUser($id)
    {
        $user = User::where('id', $id)->firstOrFail();

        //return view('admin.users.edituser', compact('user'));
        abort(404, 'Not implemented');
    }

    public function changeUsersAvatar(Request $request)
    {
        $this->validate($request, [
            'file' => 'required',
            'user_id' => 'required',
        ]);
        $editUser = Auth::user();
        $user = User::whereId($request->get('user_id'))->firstOrFail();
        $uploadedFile = $request->file('file');
        $filename = $uploadedFile->getClientOriginalName();
        Storage::disk('local')->putFileAs(
            'public/files/avatars/'.$user->id.'/'.$editUser->id,
            $uploadedFile,
            $filename
        );
        $user->avatar = Storage::url('public/files/avatars/'.$user->id.'/'.$editUser->id.'/'.$filename);
        $user->avatar_mode = 1;
        $user->save();
        AuditLogEntry::insert($editUser, 'Changed user avatar', $user, 0);

        return redirect()->back()->with('success', 'Avatar changed!');
    }

    public function resetUsersAvatar(Request $request)
    {
        $this->validate($request, [
            'user_id' => 'required',
        ]);
        $editUser = Auth::user();
        $user = User::whereId($request->get('user_id'))->firstOrFail();
        if ($user->isAvatarDefault()) {
            abort(403, 'The avatar is already the default avatar.');
        }

        $user->avatar = '/img/default-profile-img.jpg';
        $user->avatar_mode = 0;
        $user->save();
        AuditLogEntry::insert($editUser, 'Reset user avatar', $user, 0);

        return redirect()->back()->with('success', 'Avatar reset!');
    }

    public function resetUsersBio(Request $request)
    {
        $this->validate($request, [
            'user_id' => 'required',
        ]);

        $editUser = Auth::user();
        $user = User::whereId($request->get('user_id'))->firstOrFail();

        $user->bio = null;
        $user->save();

        AuditLogEntry::insert($editUser, 'Reset user bio', $user, 0);

        //Redirect
        return redirect()->back()->with('success', 'Biography reset!');
    }

    public function storeEditUser(Request $request, $id)
    {
        $user = User::find($id);
        $prevPermissions = $user->permissions;
        $user->permissions = $request->get('permissions');
        $user->save();
        $entry = new AuditLogEntry([
            'user_id' => Auth::user()->id,
            'action' => 'EDIT USER',
            'affected_id' => $user->id,
            'time' => date('Y-m-d H:i:s'),
            'private' => 0,
        ]);
        $entry->save();
        if ($prevPermissions != $user->permissions) {
            $notification = new UserNotification([
                'user_id' => $user->id,
                'content' => 'Your permissions have been updated.',
                'link' => '/dashboard',
                'dateTime' => date('Y-m-d H:i:s'),
            ]);
            $notification->save();
        }

        //return redirect()->route('users.viewprofile', $user->id)->with('success', 'User edited!');
        abort(404, 'Not implemented');

    }

    public function emailCreate($id)
    {
        $user = User::where('id', $id)->firstOrFail();

        //return view('dashboard.users.email', compact('user'));
        abort(404, 'Not implemented');

    }

    public function emailStore(Request $request)
    {
    }

    public function createUserNote(Request $request, $id)
    {
        $this->validate($request, [
            'content' => 'required',
            'position' => 'required',
        ]);

        $user = User::where('id', $id)->firstOrFail();
        $instructor = Auth::user();
        $content = $request->get('content');
        $note = new UserNote([
            'user_id' => $user->id,
            'author' => Auth::user()->id,
            'author_name' => $instructor->fullName('FLC'),
            'position' => $request->get('position'),
            'content' => $content,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);

        if ($request->get('confidential') == 'on') {
            $note->confidential = 1;
        }

        $note->save();

        return redirect()->route('users.viewprofile', $user->id)->with('success', 'Training note saved!');
    }

    public function deleteUserNote($user_id, $note_id)
    {
        $user = User::where('id', $user_id)->firstOrFail();
        $note = UserNote::where('id', $note_id)->where('user_id', $user->id)->firstOrFail();

        $entry = new AuditLogEntry([
            'user_id' => Auth::user()->id,
            'action' => 'DELETE USER NOTE '.$note->id,
            'affected_id' => $user->id,
            'time' => date('Y-m-d H:i:s'),
            'private' => 0,
        ]);
        if ($note->confidential == 1) {
            $entry->private = 1;
        }
        $entry->save();

        $note->delete();

        return redirect()->route('users.viewprofile', $user->id)->with('success', 'Training note deleted.');
    }

    public function changeAvatar(Request $request)
    {
        $this->validate($request, [
            'file' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        $user = Auth::user();
        $uploadedFile = $request->file('file');
        $filename = $uploadedFile->getClientOriginalName();
        Storage::disk('local')->putFileAs(
            'public/files/avatars/'.$user->id,
            $uploadedFile,
            $filename
        );
        $user->avatar = Storage::url('public/files/avatars/'.$user->id.'/'.$filename);
        $user->avatar_mode = 1;
        $user->save();

        return redirect()->route('dashboard.index')->with('success', 'Avatar changed!');
    }

    public function changeAvatarDiscord()
    {
        $user = Auth::user();
        $user->avatar_mode = 2;
        $user->save();
        return redirect()->route('dashboard.index')->with('success', 'Avatar changed!');
    }

    public function resetAvatar()
    {
        $user = Auth::user();
        if ($user->isAvatarDefault()) {
            abort(403, 'Your avatar is already the default avatar.');
        }

        $user->avatar_mode = 0;
        $user->save();

        return redirect()->back()->with('success', 'Avatar reset!');
    }

    public function searchUsers(Request $request)
    {
        if ($request->ajax != false) {
            abort(400, 'AJAX requests only');
        }
        $query = strtolower($request->get('query'));
        $users = User::
            where('id', 'LIKE', "%{$query}%")->
            orWhere('display_fname', 'LIKE', "%{$query}")->
            orWhere('lname', 'LIKE', "%{$query}%")->get();
        if (count($users) > 0) {
            return Response($users);
        } else {
            return Response('n/a');
        }
    }

    public function editBioIndex()
    {
        return view('dashboard.me.editbio');
    }

    public function editBio(Request $request)
    {
        $this->validate($request, [
            'bio' => 'sometimes|max:5000',
        ]);

        //Get user
        $user = Auth::user();

        //Get input
        $input = $request->get('bio');

        //Run through profanity filter
        $check = new Check();
        if ($check->hasProfanity($input)) {
            return redirect()->back()->withInput()->with('error-modal', 'Profanity was detected in your input, please remove it.');
        }

        //No swear words.. give them the new bio
        $user->bio = $input;
        $user->save();

        //Redirect
        return redirect()->back()->with('success', 'Biography saved!');
    }

    public function changeDisplayName(Request $request)
    {
        $this->validate($request, [
            'display_fname' => 'required',
            'format' => 'required',
        ]);

        //Get user
        $user = Auth::user();

        //Run through profanity filter
        $check = new Check();
        if ($check->hasProfanity($request->get('display_fname'))) {
            return redirect()->back()->withInput()->with('error', 'Profanity was detected in your display name. Please remove it');
        }

        //No swear words... give them the new name!
        $user->display_fname = $request->get('display_fname');
        if ($request->get('format') == 'showall') {
            $user->display_last_name = true;
            $user->display_cid_only = false;
        } elseif ($request->get('format') == 'showfirstcid') {
            $user->display_last_name = false;
            $user->display_cid_only = false;
        } else {
            $user->display_last_name = false;
            $user->display_cid_only = true;
        }
        $user->save();

        //Redirect
        return redirect()->back()->with('success', 'Display name saved!');
    }

    public function viewUserProfilePublic($id)
    {
        $user = User::whereId($id)->firstOrFail();

        return view('dashboard.me.publicuserprofile', compact('user'));
    }

    public function createBookingBan(Request $request, $id)
    {
        //Validate and get user
        $this->validate($request, [
           'reason' => 'required'
        ]);
        $user = User::whereId($id)->firstOrFail();

        //Is the user banned?
        if ($user->bookingBan())
        {
            abort(403, 'This user is already banned.');
        }

        //No... let's create a ban
        $ban = new ControllerBookingsBan;
        $ban->reason = $request->get(Auth::user()->fullName('FLC').' at '.date('Y-m-d H:i:s').' '.$request->get('reason'));
        $ban->user_id = $user->id;
        $ban->timestamp = date('Y-m-d H:i:s');
        $ban->save();

        //Notify them
    }

    public function removeBookingBan(Request $request, $id)
    {

    }

    public function linkDiscord()
    {
        Log::info('Linking Discord for '.Auth::id());
        return Socialite::with('discord')->setScopes(['identify'])->redirect();
    }

    public function linkDiscordRedirect() {
        $discordUser = Socialite::driver('discord')->stateless()->user();
        if (!$discordUser) {
            abort(403, 'Discord OAuth failed.');
        }
        $user = Auth::user();
        if (User::where('discord_user_id', $discordUser->id)->first()) {
            return redirect()->route('dashboard.index')->with('error-modal', 'This Discord account has already been linked by another user.');
        }
        $user->discord_user_id = $discordUser->id;
        $user->discord_dm_channel_id = app(Discord::class)->getPrivateChannel($discordUser->id);
        $user->save();
        return redirect()->route('dashboard.index')->with('success', 'Linked with account '.$discordUser->nickname. '!');
    }

    public function joinDiscordServerRedirect()
    {
        $config = new Config(config('services.discord.client_id'), config('services.discord.client_secret'), config('services.discord.redirect_join'));
        return Socialite::with('discord')->setConfig($config)->setScopes(['identify', 'guilds.join'])->redirect();
    }

    public function joinDiscordServer()
    {
        $discord = new DiscordClient(['token' => config('services.discord.token')]);
        $config = new Config(config('services.discord.client_id'), config('services.discord.client_secret'), config('services.discord.redirect_join'));
        $discordUser = Socialite::driver('discord')->setConfig($config)->user();
        $args = array(
            'guild.id' => 598023748741758976,
            'user.id' => intval($discordUser->id),
            'access_token' => $discordUser->token,
             'nick' => Auth::user()->fullName('FL')
        );
        if (Auth::user()->rosterProfile) {
            if (Auth::user()->rosterProfile->status == 'training') {
                $args['roles'] = array(717155319981146182);
            }
            elseif (Auth::user()->rosterProfile->status == 'home') {
                $args['roles'] = array(713914598750683157);
            }
            elseif (Auth::id() == '1427371') {
                $args['roles'] = array(673725707259609093);
            }
        }
        else {
            $args['roles'] = array(482835389640343562);
        }
        $discord->guild->addGuildMember($args);
        Auth::user()->notify(new DiscordWelcome());
        $discord->channel->createMessage(['channel.id' => 695849973585149962, 'content' => '<@'.$discordUser->id.'> ('.Auth::id().') has joined.']);
        return redirect()->route('dashboard.index')->with('success', 'You have joined the Winnipeg Discord server!');
    }

    public function unlinkDiscord()
    {
        $discord = new DiscordClient(['token' => config('services.discord.token')]);
        $user = Auth::user();
        if ($user->memberOfCZWGGuild()) {
            try {
                $discord->guild->removeGuildMember(['guild.id' => 598023748741758976, 'user.id' => $user->discord_user_id]);
                $discord->channel->createMessage(['channel.id' => 695849973585149962, 'content' => '<@'.$user->discord_user_id.'> ('.Auth::id().') has unlinked their account and has been kicked.']);
            } catch (Exception $ex) {
                Log::error($ex->getMessage());
            }
        }
        $user->discord_user_id = null;
        $user->discord_dm_channel_id = null;
        if ($user->avatar_mode == 2) {
            $user->avatar_mode = 0;
        }
        $user->save();
        return redirect()->route('dashboard.index')->with('info', 'Account unlinked.');
    }

    public function preferences()
{
        $preferences = Auth::user()->preferences;
        return view('dashboard.me.preferences', compact('preferences'));
    }
}
