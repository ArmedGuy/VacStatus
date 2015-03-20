<?php namespace VacStatus\Update;

use VacStatus\Models\UserMail;
use VacStatus\Models\Subscription;
use VacStatus\Models\UserListProfile;
use VacStatus\Models\ProfileBan;

use VacStatus\Steam\Steam;
use VacStatus\Steam\SteamAPI;

use DateTime;
use DateInterval;

/*

	-> Grab all profiles from userlist
		-> loop through userlist
			-> add profile_id to an array under userlist_id key
				e.) user_list_id => [profile_id]
			-> add 'Profile' to big array and dont add duplicate profile_id
				e.) $profiles = [Profile] -> not ONLY profile_id
		->  Loop through

 */

class SubscriptionCheck
{
	private $userMail;
	private $userLists;
	private $subscriptionIds;
	private $profiles;

	public function __construct($lastCheckedSubscription)
	{
		// TESTING PURPOSES
		$lastCheckedSubscription = -1;


		$userMail = UserMail::whereRaw('user_mail.id > ? and (user_mail.verify = ? or user_mail.pushbullet_verify = ?)', array($lastCheckedSubscription, 'verified', 'verified'))
			->first();

		if(!isset($userMail->id))
		{
			$userMail = UserMail::whereRaw('user_mail.id > ? and (user_mail.verify = ? or user_mail.pushbullet_verify = ?)', array(-1, 'verified', 'verified'))
				->first();
		}

		$userLists = Subscription::where('subscription.user_id', $userMail->user_id)
			->whereNull('user_list.deleted_at')
			->whereNull('subscription.deleted_at')
			->leftJoin('user_list', 'subscription.user_list_id', '=', 'user_list.id')
			->distinct()
			->get([
				'user_list.id',
				'user_list.title',

				'subscription.id as sub_id',
		      	'subscription.updated_at'
			]);

		$userListIds = [];
		$subscriptionIds = [];

		foreach($userLists as $userList)
		{
			$userListIds[] = $userList->id;
			$subscriptionIds[] = $userList->sub_id;
		}

		$profiles = UserListProfile::whereIn('user_list_profile.user_list_id', $userListIds)
			->leftJoin('profile', 'user_list_profile.profile_id', '=', 'profile.id')
			->leftJoin('profile_ban', 'profile_ban.profile_id', '=', 'profile.id')
			->whereNull('user_list_profile.deleted_at')
			->distinct()
			->get([
		     	'user_list_profile.user_list_id',

		     	'profile.id',
				'profile.display_name',
				'profile.small_id',
				'profile.avatar_thumb',

				'profile_ban.vac',
				'profile_ban.vac_days',
				'profile_ban.community',
				'profile_ban.trade',
				'profile_ban.unban',
				'profile_ban.updated_at',
				'profile_ban.vac_banned_on',
			]);

		$this->profiles = $profiles;
		$this->userMail = $userMail;
		$this->userLists = $userLists;
		$this->subscriptionIds = $subscriptionIds;
	}

	public function setSubscription()
	{
		return $this->userMail->id;
	}

	public function run()
	{
		$this->check();

		$toUpdate = Subscription::whereIn('id', $this->subscriptionIds)->get();
		foreach($toUpdate as $subscription)
		{
			$subscription->touch();
		}
	}

	private function check()
	{
		$userMail = $this->userMail;
		$userLists = $this->userLists;	
		$profiles = $this->profiles;

		$profilesToSendForNotification = [];
		$getSmallIds = [];

		foreach($userLists as $userList)
		{
			$userListProfiles = $profiles->where('user_list_id', $userList->id);
			foreach($userListProfiles as $profile)
			{
				if($userList->updated_at->timestamp < $profile->updated_at->timestamp)
				{
					$profilesToSendForNotification[$profile->id] = $profile;
				}

				if(!in_array($profile->small_id, $getSmallIds))
				{
					$getSmallIds[] = $profile->small_id;
				}
			}
		}


		$steamAPI = new SteamAPI('ban');
		$steamAPI->setSmallId($getSmallIds);
		$steamBans = $steamAPI->run();

		if($steamAPI->error()) return ['error' => $steamAPI->errorMessage()];
		if(!isset($steamBans->players[0])) return ['error' => 'profile_null'];

		$steamBans = $steamBans->players;

		$indexSave = [];
		foreach($steamBans as $k => $ban)
		{
			$indexSave[Steam::toSmallId($ban->SteamId)] = $k;
		}

		foreach($getSmallIds as $k => $smallId)
		{
			$steamBan = $steamBans[$indexSave[$smallId]];
			$profile = $profiles->where('small_id', $smallId)->first();

			$skipProfileBan = false;

			$newVacBanDate = new DateTime();
			$newVacBanDate->sub(new DateInterval("P{$steamBan->DaysSinceLastBan}D"));

			$profileBan = [];
			$profileBan['vac'] = $steamBan->NumberOfVACBans;
			$profileBan['community'] = $steamBan->CommunityBanned;
			$profileBan['trade'] = $steamBan->EconomyBan != 'none';
			$profileBan['vac_banned_on'] = $newVacBanDate->format('Y-m-d');


			if($profile->vac != $profileBan['vac'] ||
				$profile->community != $profileBan['community'] ||
				$profile->trade != $profileBan['trade'])
			{
			 	$oldProfileBan = ProfileBan::where('profile_id', $profile->id)->first();
			 	$oldProfileBan->save($profileBan);

				$profilesToSendForNotification[$profile->id] = $profile;
			}
		}

		if(count($profilesToSendForNotification) == 0) return false;

		$userInfo = [
			'send' => [
				'email' => $userMail->verify == "verified" ? $userMail->email : false,
				'pushbullet' => $userMail->pushbullet_verify == "verified" ? $userMail->pushbullet : false,
			],
			'profiles' => $profilesToSendForNotification
		];

		return $userInfo;
	}


}