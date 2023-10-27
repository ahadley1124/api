<?php

namespace App\Http\Controllers\API\v2;

use App\Helpers\RatingHelper;
use App\Role;
use App\User;
use http\Env\Request;

class IntegrationController extends APIController
{
    public function getStaffMembers() {
        function makeOutput($user) {
            $c = $user->toArray();

            unset($c['flag_broadcastOptedIn']);
            unset($c['email']);
            unset($c['flag_preventStaffAssign']);
            unset($c['created_at']);
            unset($c['updated_at']);
            unset($c['flag_needbasic']);
            unset($c['flag_xferOverride']);
            unset($c['promotion_eligible']);
            unset($c['transfer_eligible']);
            unset($c['lastactivity']);
            unset($c['last_cert_sync']);

            $c['rating_short'] = RatingHelper::intToShort($c['rating']);
            $c['visiting_facilities'] = $user->visits->toArray();
            return $c;
        }
        $staffRoles = ['ATM', 'DATM', 'TA', 'EC', 'FE', 'WM', 'INS', 'MTR'];

        $userRoles = Role::whereIn('role', $staffRoles)->get();

        $controllers = [];
        foreach ($userRoles as $r) {
            $user = $r->user;
            $controllers[$user->cid] = makeOutput($user);
        }

        $usersByRating = User::whereIn('rating', [8, 10])->get();

        foreach ($usersByRating as $user) {
            $controllers[$user->cid] = makeOutput($user);
        }


        return response()->api($controllers);
    }

}