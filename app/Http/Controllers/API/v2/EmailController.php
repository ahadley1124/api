<?php

namespace App\Http\Controllers\API\v2;

use App\EmailAccounts;
use App\Facility;
use App\Helpers\EmailHelper;
use App\Helpers\RoleHelper;
use App\Role;
use App\User;
use Illuminate\Http\Request;

class EmailController extends APIController
{
    /**
     * @OA\Get(
     *     path="/email",
     *     summary="Get info of VATUSA email address assigned for user. [Private]",
     *     description="Get info of VATUSA email address assigned for user. CORS Restricted.",
     *     responses={"application/json"},
     *     tags={"email"},
     *     security={"jwt","session"},
     *     @OA\Parameter(description="JWT Token", in="header", name="bearer", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response="401",
     *         description="Unauthenticated",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Unauthenticated"}},
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="OK",
     *         @OA\Schema(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="type", type="string", description="Type of email
     *                                                (forward/full/static)"),
     *                 @OA\Property(property="email", type="string", description="Email address"),
     *                 @OA\Property(property="destination", type="string", description="Destination for email
     *                                                       forwards")
     *             ),
     *         ),
     *         content={
     *              "application/json":{
     *                      {"type":"forward","email":"test@vatusa.net","destination":"test2@vatusa.net"},
     *                      {"type":"full","email":"easy@vatusa.net"}
     *              }
     *         }
     *     )
     * )
     */
    public function getIndex()
    {
        if (!\Auth::check()) {
            return response()->unauthenticated();
        }

        $response = [];
        $return = Role::where('cid', \Auth::user()->cid)->get();
        foreach ($return as $row) {
            if ($row->facility === "ZHQ" && preg_match("/^US(\d+)$/", $row->role, $matches)) {
                $temp = [
                    "type"  => EmailHelper::$email_full,
                    "email" => strtolower(substr(\Auth::user()->fname, 0, 1) . "." . \Auth::user()->lname) . "@vatusa.net",
                ];
                /*if ($temp['type'] === EmailHelper::$email_forward || $temp['type'] === EmailHelper::$email_static) {
                    $temp['destination'] = implode(",", EmailHelper::forwardDestination($temp['email']));
                }*/
                $response[] = $temp;
            }
            if ($row->facility !== "ZHQ" && $row->facility !== "ZAE" && in_array($row->role,
                    ["ATM", "DATM", "TA", "EC", "FE", "WM"])) {
                $temp = [
                    "type"  => EmailHelper::isStaticForward(strtolower($row->facility . "-" . $row->role . "@vatusa.net")) ?
                        EmailHelper::$email_static :
                        EmailHelper::getType(strtolower($row->facility . "-" . $row->role . "@vatusa.net")),
                    "email" => strtolower($row->facility . "-" . $row->role . "@vatusa.net"),

                ];
                if ($temp['type'] === EmailHelper::$email_forward || $temp['type'] === EmailHelper::$email_static) {
                    $temp["destination"] = implode(",", EmailHelper::forwardDestination($temp['email']));
                }
                $response[] = $temp;
            }
        }
        $return = EmailAccounts::where("cid", \Auth::user()->cid)->get();
        foreach ($return as $row) {
            $domain = $row->fac->hosted_email_domain;
            if (!$domain) {
                continue;
            }
            $temp = [
                "type"  => EmailHelper::getType(strtolower($row->username . "@" . $domain)),
                "email" => strtolower($row->username . "@" . $domain)
            ];
            if ($temp['type'] === EmailHelper::$email_forward) {
                $temp['destination'] = implode(",", EmailHelper::forwardDestination($temp["email"]));
            }
            $response[] = $temp;
        }

        return response()->api($response);
    }

    /**
     * @param $address
     *
     * @return string
     *
     * @OA\Get(
     *     path="/email/(address)",
     *     summary="Get info of VATUSA email address. [Private]",
     *     description="Get info of VATUSA email address. CORS Restricted.",
     *     responses={"application/json"},
     *     tags={"email"},
     *     security={"jwt","session"},
     *     @OA\Parameter(description="JWT Token", in="header", name="bearer", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(description="Email address", in="path", name="address", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response="400",
     *         description="Bad request",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={{"application/json":{"status"="error","msg"="Missing required
     *         field"}},{"application/json":{"status"="error","msg"="Password too weak"}}},
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Unauthenticated",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Unauthenticated"}},
     *     ),
     *     @OA\Response(
     *         response="403",
     *         description="Forbidden",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Forbidden"}},
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="OK",
     *         @OA\Schema(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="type", type="string", description="Type of email
     *                                                (forward/full/static)"),
     *                 @OA\Property(property="email", type="string", description="Email address"),
     *                 @OA\Property(property="destination", type="string", description="Destination for email
     *                                                       forwards"),
     *                 @OA\Property(property="static", type="boolean", description="Is address static?")
     *             ),
     *         ),
     *         content={
     *              "application/json":{
     *                      "type":"full","email":"easy@vatusa.net"
     *              }
     *         }
     *     )
     * )
     */
    public function getEmail($address)
    {
        if (!\Auth::check()) {
            return response()->unauthenticated();
        }

        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return response()->api(generate_error("Malformed request"), 400);
        }
        if (!\Auth::user()->hasEmailAccess($address)) {
            return response()->api(generate_error("Forbidden"), 403);
        }

        $response = [
            'type'  => EmailHelper::isStaticForward($address) ? EmailHelper::$email_static : EmailHelper::getType($address),
            'email' => $address
        ];

        if ($response['type'] === EmailHelper::$email_forward || $response['type'] === EmailHelper::$email_static) {
            $response['destination'] = implode(",", EmailHelper::forwardDestination($address));
        }

        return response()->api($response);
    }

    /**
     * @OA\Put(
     *     path="/email",
     *     summary="Modify email account. [Private]",
     *     description="Modify email account. Static forwards may only be modified by the ATM, DATM or WM. CORS
     *     Restricted.", responses={"application/json"}, tags={"email"}, security={"jwt","session"},
     *     @OA\Parameter(description="JWT Token", in="header", name="bearer", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(description="Email Address", in="query", name="email", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(description="Set destination for forwarded address", in="query", name="destination",
     *                                     @OA\Schema(type="string")),
     *     @OA\Parameter(description="Password for full account", in="query", name="password", @OA\Schema(type="string")),
     *     @OA\Parameter(description="Is static forward or not", in="query", name="static", @OA\Schema(type="string")),
     *     @OA\Response(
     *         response="400",
     *         description="Bad request",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={{"application/json":{"status"="error","msg"="Missing required
     *         field"}},{"application/json":{"status"="error","msg"="Password too weak"}}},
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Unauthenticated",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Unauthenticated"}},
     *     ),
     *     @OA\Response(
     *         response="403",
     *         description="Forbidden",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Forbidden"}},
     *     ),
     *     @OA\Response(
     *         response="409",
     *         description="Conflict, usually caused by mismatched parameters",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Ambiguous request"}},
     *     ),
     *     @OA\Response(
     *         response="500",
     *         description="Server error",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Unknown error"}},
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="OK",
     *         @OA\Schema(ref="#/components/schemas/OK"),
     *         content={"application/json":{"status"="OK","testing"=false}}
     *     )
     * )
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function putIndex(Request $request)
    {
        if (!\Auth::check()) {
            return response()->unauthenticated();
        }

        $email = $request->input("email", null);
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->api(generate_error("Missing required field", true), 400);
        }

        if (!\Auth::user()->hasEmailAccess($email)) {
            return response()->api(generate_error("Forbidden", true), 403);
        }

        if (EmailHelper::isStaticForward($email) &&
            (!RoleHelper::has(\Auth::user()->cid, strtoupper(substr($email, 0, 3)), ['ATM', 'DATM', 'WM']) &&
                !\Auth::user()->hasEmailAccess($email))) {
            return response()->api(generate_error("Forbidden static rules"), 403);
        }

        /* Now determine which course of action:
         * - Full Account (password set)
         * - Forward (destination set)
         */
        $password = $request->input("password", null);
        $destination = preg_replace('/\s+/', '', $request->input("destination", null));
        if (!$password && !$destination) {
            return response()->api(generate_error("Missing required field", true), 400);
        }
        // We cannot determine full or forward if both are set, causing ambiguous information
        if ($password && $destination) {
            return response()->api(generate_error("Ambiguous request", true), 409);
        }

        if (!isTest()) {
            // Now handle, *FULL ACCOUNT*
            if ($password !== null) {
                if (strlen($password) < 6) {
                    return response()->api(generate_error("Password too weak", true), 400);
                }
                if (EmailHelper::getType($email) === EmailHelper::$email_full) {
                    if (EmailHelper::setPasswordEmail($email, $password)) {
                        return response()->ok();
                    } else {
                        return response()->api(generate_error("Unknown error", true), 500);
                    }
                } else {
                    if (!EmailHelper::deleteForward($email)) {
                        \Log::critical("Error deleting forward for $email to change to full account");

                        return response()->api(generate_error("Unknown error", true), 500);
                    }
                    if (!EmailHelper::addEmail($email, $password)) {
                        \Log::critical("Error creating full account $email");

                        return response()->api(generate_error("Unknown error", true), 500);
                    }

                    return response()->ok();
                }
            }

            // Now handle, *FORWARD*
            $destinationArr = [];
            if (strpos($destination, ",")) {
                $destinationArr = explode(",", $destination);
            }
            if (!empty($destinationArr)) {
                foreach ($destinationArr as $d) {
                    if (!filter_var($d, FILTER_VALIDATE_EMAIL)) {
                        return response()->api(generate_error("Invalid email $d", true), 400);
                    }
                }
            } else {
                if (!filter_var($destination, FILTER_VALIDATE_EMAIL)) {
                    return response()->api(generate_error("Invalid email $destination", true), 400);
                }
            }
            if (EmailHelper::getType($email) === EmailHelper::$email_full) {
                if (!EmailHelper::deleteEmail($email)) {
                    \Log::critical("Error deleting full account $email to set to forward to $destination");

                    return response()->api(generate_error("Unknown error", true), 500);
                }
            }
            if (!EmailHelper::setForward($email, $destination)) {
                \Log::critical("Error setting forward $email -> $destination");

                return response()->api(generate_error("Unknown error", true), 500);
            }

            if ($request->input("static") == "true") {
                EmailHelper::chgEmailConfig($email, EmailHelper::$config_static, $destination);
            } else {
                EmailHelper::chgEmailConfig($email, EmailHelper::$config_user, $destination);
            }
        }

        return response()->ok();
    }

    /**
     * @OA\Get(
     *     path="/email/hosted",
     *     summary="Get VATUSA hosted email accounts.  [Private]",
     *     description="Get VATUSA hosted email accounts. CORS Restricted.",
     *     responses={"application/json"},
     *     tags={"email"},
     *     security={"jwt","session"},
     *     @OA\Parameter(description="JWT Token", in="header", name="bearer", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(description="Facility IATA ID", in="query", name="facility", @OA\Schema(type="string")),
     *     @OA\Response(
     *         response="401",
     *         description="Unauthenticated",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Unauthenticated"}},
     *     ),
     *     @OA\Response(
     *         response="403",
     *         description="Forbidden",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Forbidden"}},
     *     ),
     *     @OA\Response(
     *         response="404",
     *         description="Not Found",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Not Found"}},
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="OK",
     *         @OA\Schema(
     *             type="object",
     *             @OA\Property(
     *                 property="emails",
     *                 type="array",
     *                 @OA\Items(
     *                     ref="#/components/schemas/EmailAccounts"
     *                 ),
     *             ),
     *         ),
     *     )
     * )
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function getHosted(Request $request)
    {
        if (!\Auth::check()) {
            return response()->unauthenticated();
        }

        if (!$request->has("facility")) {
            return response()->malformed();
        }

        $fac = Facility::find($request->input("facility"));
        if (!$fac) {
            return response()->notfound();
        }

        if (!RoleHelper::isVATUSAStaff() && !RoleHelper::isSeniorStaff(\Auth::user()->cid,
                $fac->id) && !RoleHelper::has(\Auth::user()->cid, $fac->id, "WM")) {
            return response()->forbidden();
        }

        if ($fac->hosted_email_domain == "") {
            return response()->forbidden();
        }

        $return = EmailAccounts::where("facility", $fac->id)->orderBy("username")->get()->toArray();

        return response()->ok(["emails" => $return]);
    }

    /**
     * @OA\Put(
     *     path="/email/hosted/{fac}/{username}",
     *     summary="Modify VATUSA hosted email account. [Private]",
     *     description="Modify VATUSA hosted email account. CORS Restricted.",
     *     responses={"application/json"},
     *     tags={"email"},
     *     security={"jwt","session"},
     *     @OA\Parameter(description="JWT Token", in="header", name="bearer", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(description="Email username", in="query", name="username", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(description="Facility IATA ID", in="query", name="facility", @OA\Schema(type="string")),
     *     @OA\Parameter(description="Owning CID", in="query", name="cid", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response="400",
     *         description="Bad request",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={{"application/json":{"status"="error","msg"="Missing required
     *         field"}},{"application/json":{"status"="error","msg"="Password too weak"}}},
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Unauthenticated",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Unauthenticated"}},
     *     ),
     *     @OA\Response(
     *         response="403",
     *         description="Forbidden",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Forbidden"}},
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="OK",
     *         @OA\Schema(ref="#/components/schemas/OK"),
     *         content={"application/json":{"status"="OK","testing"=false}}
     *     )
     * )
     * @param \Illuminate\Http\Request $request
     * @param null                     $facility
     * @param null                     $username
     *
     * @return \Illuminate\Http\Response
     */
    public function postHosted(Request $request, $facility = null, $username = null)
    {
        if (!\Auth::check()) {
            return response()->unauthenticated();
        }

        if (!$request->has("cid")) {
            return response()->malformed();
        }

        $fac = Facility::find($facility);
        if (!$fac) {
            return response()->notfound();
        }

        if (!RoleHelper::isVATUSAStaff() && !RoleHelper::isSeniorStaff(\Auth::user()->cid,
                $fac->id) && !RoleHelper::has(\Auth::user()->cid, $fac->id, "WM")) {
            return response()->forbidden();
        }

        $user = User::find($request->input("cid"));
        if (!$user) {
            return response()->notfound();
        }

        if ($fac->hosted_email_domain == "") {
            return response()->forbidden();
        }

        if (!filter_var($username . "@" . $fac->hosted_email_domain, FILTER_VALIDATE_EMAIL)) {
            return response()->malformed(1234);
        }

        $account = EmailAccounts::where("username", $username)->where("facility", $fac->id)->first();
        if (!$account) {
            EmailHelper::addEmail($username . "@" . $fac->hosted_email_domain, str_random()); // Make them reset
            $account = new EmailAccounts();
            $account->username = $username;
            $account->facility = $fac->id;
        }
        $account->cid = $request->input("cid");
        if (!isTest()) {
            $account->save();
        }

        return response()->ok();
    }

    /**
     * @OA\Delete(
     *     path="/email/hosted/{fac}/{username}",
     *     summary="Delete VATUSA hosted email account. [Private]",
     *     description="Delete VATUSA hosted email account. CORS Restricted.",
     *     responses={"application/json"},
     *     tags={"email"},
     *     security={"jwt","session"},
     *     @OA\Parameter(description="JWT Token", in="header", name="bearer", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(description="Email username", in="query", name="username", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(description="Facility IATA ID", in="query", name="facility", @OA\Schema(type="string")),
     *     @OA\Response(
     *         response="400",
     *         description="Bad request",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={{"application/json":{"status"="error","msg"="Missing required
     *         field"}},{"application/json":{"status"="error","msg"="Password too weak"}}},
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Unauthenticated",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Unauthenticated"}},
     *     ),
     *     @OA\Response(
     *         response="403",
     *         description="Forbidden",
     *         @OA\Schema(ref="#/components/schemas/error"),
     *         content={"application/json":{"status"="error","msg"="Forbidden"}},
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="OK",
     *         @OA\Schema(ref="#/components/schemas/OK"),
     *         content={"application/json":{"status"="OK","testing"=false}}
     *     )
     * )
     * @param \Illuminate\Http\Request $request
     * @param                          $facility
     * @param                          $username
     *
     * @return \Illuminate\Http\Response
     */
    public function deleteHosted(Request $request, $facility, $username)
    {
        if (!\Auth::check()) {
            return response()->unauthenticated();
        }

        $fac = Facility::find($facility);
        if (!$fac) {
            return response()->notfound();
        }

        if (!RoleHelper::isVATUSAStaff() && !RoleHelper::isSeniorStaff(\Auth::user()->cid,
                $fac->id) && !RoleHelper::has(\Auth::user()->cid, $fac->id, "WM")) {
            return response()->forbidden();
        }

        if ($fac->hosted_email_domain == "") {
            return response()->forbidden();
        }

        if (!filter_var($username . "@" . $fac->hosted_email_domain, FILTER_VALIDATE_EMAIL)) {
            return response()->malformed();
        }

        if (!isTest()) {
            EmailHelper::deleteEmail($username . "@" . $fac->hosted_email_domain);
            $account = EmailAccounts::where("username", $username)->where("facility", $fac->id)->delete();
        }

        return response()->ok();
    }
}
