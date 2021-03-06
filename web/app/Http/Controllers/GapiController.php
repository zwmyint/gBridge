<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Accesskey;
use App\Device;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class GapiController extends Controller
{
    /**
     * Show a form for the user to log in with his accesskey.
     *
     * @return \Illuminate\Http\Response
     */
    public function auth(Request $request)
    {
        return view('gapi.auth', [
            'site_title' => 'Authenticate',
            //those are GET-Parameters supplied by google
            'client_id' => $request->input('client_id', ''),
            'response_type' => $request->input('response_type', ''),
            'redirect_uri' => $request->input('redirect_uri', ''),
            'state' => $request->input('state', ''),
        ]);
    }

    /**
     * Check authentication.
     *
     * @return \Illuminate\Http\Response
     */
    public function checkauth(Request $request)
    {
        if(!Auth::check()){
            $this->validate($request, [
                'email' => 'bail|required|string|email|max:255',
                'password' => 'bail|required|string',
            ]);
        }

        //check parameters provided by Google
        $googleerror_prefix = "The request by Google Home was malformed. Please try again in a few minute. If this problem persists, please contact the team of Kappelt gBridge. ";
        
        if($request->input('client_id', '__y') != env('GOOGLE_CLIENTID', '__z')){
            return redirect()->back()->withInput()->with('error', $googleerror_prefix . 'Invalid Client ID has been provided!');
        }
        if($request->input('response_type', '') != 'token'){
            return redirect()->back()->withInput()->with('error', $googleerror_prefix . 'Unkown Response Type requested!');
        }
        if($request->input('redirect_uri', '__x') != ('https://oauth-redirect.googleusercontent.com/r/' . env('GOOGLE_PROJECTID', ''))){
            return redirect()->back()->withInput()->with('error', $googleerror_prefix . 'Invalid redirect Request!');
        }
        if(!$request->input('state')){
            return redirect()->back()->withInput()->with('error', $googleerror_prefix . 'No State given!');
        }

        if(!Auth::check()){
            //User is not authenticated in this browser. Try logging
            if(!Auth::once(['email' => $request->input('email', ''), 'password' => $request->input('password', '')])){
                return redirect()->back()->withInput()->with('error', "Your account doesn't exist or the credentials don't match our records!");
            }
        }

        if(isset(Auth::user()->verify_token)) {
            return redirect()->back()->withInput()->with('error', "You need to confirm your account. We have sent you an activation code, please check your email account. Check the spam folder for this mail, contact support under gbridge@kappelt.net if you haven't received the confirmation");
        }

        $accesskey = new Accesskey;
        $accesskey->google_key = password_hash(str_random(32), PASSWORD_BCRYPT);
        $accesskey->user_id = Auth::user()->user_id;

        $accesskey->save();

        return redirect($request->input('redirect_uri') . '#access_token=' . $accesskey->google_key . '&token_type=bearer&state=' . $request->input('state'));
    }

    /**
     * Handle an apicall by google
     *
     * @return \Illuminate\Http\Response
     */
    function apicall(Request $laravel_request){

        $request = json_decode($laravel_request->getContent(), true);

        //check, whether requestId is present
        if(!isset($request['requestId'])){
            return $this->errorResponse("", ErrorCode::protocolError, true);
        }
        $requestid = $request['requestId'];

        $accesskey = $laravel_request->header('Authorization', '');
        $accesskey = Accesskey::where('google_key', str_replace('Bearer ', '', $accesskey))->get();

        if(count($accesskey) < 1){
            return $this->errorResponse($requestid, ErrorCode::authFailure);
        }
        $accesskey = $accesskey[0];
        $user = $accesskey->user;

        //See https://developers.google.com/actions/smarthome/create-app for information about the JSON request format
        if(!isset($request['inputs'])){
            return $this->errorResponse($requestid, ErrorCode::protocolError);
        }

        $input = $request['inputs'][0];

        if(!isset($input['intent'])){
            error_log("Intent is undefined!");
            return $this->errorResponse($requestid, ErrorCode::protocolError);
        }

        Redis::hset('gbridge:u' . $user->user_id . ':d0', 'grequestid', $requestid);

        //Check for users device limit
        if($user->devices()->count() > $user->device_limit){
            return $this->errorResponse($requestid, ErrorCode::deviceTurnedOff);
        }

        if($input['intent'] === 'action.devices.SYNC'){
            //sync-intent
            Redis::hset('gbridge:u' . $user->user_id . ':d0', 'grequesttype', 'SYNC');
            Redis::publish('gbridge:u' . $user->user_id . ':d0:grequest', 'SYNC');
            return $this->handleSync($user, $requestid);
        }elseif($input['intent'] === 'action.devices.QUERY'){
            //query-intent
            Redis::hset('gbridge:u' . $user->user_id . ':d0', 'grequesttype', 'QUERY');
            Redis::publish('gbridge:u' . $user->user_id . ':d0:grequest', 'QUERY');
            return $this->handleQuery($user, $requestid, $input);
        }elseif($input['intent'] === 'action.devices.EXECUTE'){
            //execute-intent
            Redis::hset('gbridge:u' . $user->user_id . ':d0', 'grequesttype', 'EXECUTE');
            Redis::publish('gbridge:u' . $user->user_id . ':d0:grequest', 'EXECUTE');
            return $this->handleExecute($user, $requestid, $input);
        }elseif($input['intent'] === 'action.devices.DISCONNECT'){
            Redis::hset('gbridge:u' . $user->user_id . ':d0', 'grequesttype', 'DISCONNECT');
            Redis::publish('gbridge:u' . $user->user_id . ':d0:grequest', 'DISCONNECT');
            $accesskey->delete();
            return response()->json([]);
        }else{
            //unknown intent
            error_log('Unknown intent: "' . $input['intent'] . '"');
            return $this->errorResponse($requestid, ErrorCode::protocolError);
        }
    }

    /**
     * Handle the Sync-Intent
     * @param user User object.
     * @param requestid The request id
     */
    private function handleSync($user, $requestid){
        $response = [
            'requestId' => $requestid,
            'payload' => [
                'devices' => [],
                'agentUserId' => $user->user_id            //the agentUserId is here the user_id
            ]
        ];

        $devices = $user->devices;
        
        foreach($devices as $device){
            $trait_googlenames = $device->traits->pluck('gname')->toArray(); 
            $trait_googlenames = array_unique($trait_googlenames);      //Unique: Some traits (for thermostats) are specified multiple times

            $deviceBuilder = [
                'id' => $device->device_id,
                'type' => $device->deviceType->gname,
                'traits' => $trait_googlenames,
                'name' => [
                    'defaultNames' => ['Kappelt Virtual Device'],
                    'name' => $device->name
                ],
                //when hosted by us we have to implement report state.
                //I do not recommend that in a self-hosted environment since it is just useless effort for this application
                'willReportState' => env('KSERVICES_HOSTED', false) ? true:false,
                'deviceInfo' => [
                    'manufacturer' => 'Kappelt kServices'
                ]
            ];

            /** CUSTOM MODIFICATIONS FOR TYPE: "Scene" */
            if($device->deviceType->shortname == 'Scene'){
                $deviceBuilder['willReportState'] = false;  //Scene will never report state, since they are write-only devs
                $deviceBuilder['attributes'] = [
                    'sceneReversible' => false              //scenes are not (yet) reversible in this implementation
                ];
            }
            /** END CUSTOM MODIFICATIONS */

            /** CUSTOM MODIFICATIONS FOR TYPE: "Thermostat" */
            //Check if the "TemperatureSetting" trait is specified for this device
            $modes = $device->traits->where('shortname', 'TempSet.Mode');
            if($modes->count()){
                $modes = Collection::make(json_decode($modes->first()->pivot->config, true))->get('modesSupported');
                if(empty($modes)){
                    $modes = ['on', 'off'];
                }
            }else{
                $modes = ['on', 'off'];
            }

            if($device->deviceType->shortname == 'Thermostat'){
                $deviceBuilder['attributes'] = [
                    'thermostatTemperatureUnit' => 'C',              //only degrees C are supported as mode
                    'availableThermostatModes' => implode(',', $modes)
                ];
            }
            /** END CUSTOM MODIFICATIONS */

            $response['payload']['devices'][] = $deviceBuilder;
        }
        //error_log(json_encode($response));
        return response()->json($response);
    }

    /**
     * Handle the Query-Intent
     * @param user The user object
     * @param requestid The request id
     * @param input the data that shall be handled
     */
    function handleQuery($user, $requestid, $input){
        $response = [
            'requestId' => $requestid,
            'payload' => [
                'devices' => []
            ]
        ];

        $userid = $user->user_id;

        if(!isset($input['payload']['devices'])){
            return $this->errorResponse($requestid, ErrorCode::protocolError);
        }

        foreach($input['payload']['devices'] as $device){
            $deviceId = $device['id'];
            $device = Device::where('device_id', $deviceId)->get();
            $traits = [];
            if(count($device) > 0){
                $traits = $device[0]->traits;
            }

            $response['payload']['devices'][$deviceId] = [];
            if(count($traits) > 0){
                $response['payload']['devices'][$deviceId]['online'] = true;
            }else{
                $response['payload']['devices'][$deviceId]['online'] = false;
            }

            $powerstate = Redis::hget("gbridge:u$userid:d$deviceId", 'power');
            if(!is_null($powerstate)){
                if($powerstate == '0'){
                    $response['payload']['devices'][$deviceId]['online'] = false;
                }
            }

            foreach($traits as $trait){
                $traitname = strtolower($trait->shortname);

                $value = Redis::hget("gbridge:u$userid:d$deviceId", $traitname);
                
                //Special handling/ conversion for certain traits.
                //Setting default values if not set by user before
                if($traitname == 'onoff'){
                    if(is_null($value)){
                        $value = false;
                    }else{
                        $value = $value ? true:false;
                    }
                    $traitname = 'on';

                    $response['payload']['devices'][$deviceId][$traitname] = $value;
                }elseif($traitname == 'brightness'){
                    if(is_null($value)){
                        $value = 0;
                    }else{
                        $value = intval($value);
                    }

                    $response['payload']['devices'][$deviceId][$traitname] = $value;
                }elseif($traitname == 'tempset.mode'){
                    if(is_null($value)){
                        $value = 'off';
                    }

                    $response['payload']['devices'][$deviceId]['thermostatMode'] = $value;
                }elseif($traitname == 'tempset.setpoint'){
                    if(is_null($value)){
                        $value = 0.0;
                    }else{
                        $value = floatval($value);
                    }
                    
                    $response['payload']['devices'][$deviceId]['thermostatTemperatureSetpoint'] = $value;
                }elseif($traitname == 'tempset.ambient'){
                    if(is_null($value)){
                        $value = 0.0;
                    }else{
                        $value = floatval($value);
                    }
                    
                    $response['payload']['devices'][$deviceId]['thermostatTemperatureAmbient'] = $value;
                }elseif($traitname == 'tempset.humidity'){
                    if(is_null($value)){
                        $value = 20.1;
                    }else{
                        $value = floatval($value);
                    }
                    
                    $traitconf = Collection::make(json_decode($trait->pivot->config, true));

                    if($traitconf->get('humiditySupported')){
                        $response['payload']['devices'][$deviceId]['thermostatHumidityAmbient'] = $value;
                    }
                }elseif($traitname == 'scene'){
                    //no value is required for scene, only "online" information
                }else{
                    error_log("Unknown trait:\"$traitname\" for user $userid in query");
                    $response['payload']['devices'][$deviceId]['online'] = false;
                }

                
            }
        }
        //error_log(json_encode($response));
        return response()->json($response);
    }

    /**
     * Handle the Execute-Intent
     * @param user The user object
     * @param requestid The request id
     * @param input the data that shall be handled
     */
    function handleExecute($user, $requestid, $input){
        if(!isset($input['payload']['commands'])){
            return $this->errorResponse($requestid, ErrorCode::protocolError);
        }

        $handledDeviceIds = [];         //array of all device ids that are handled
        $successfulDeviceIds = [];      //array of all device ids that are handled successfully (e.g. are not offline and everything went well)

        foreach($input['payload']['commands'] as $command){
            $deviceIds = array_map(function($device){return $device['id'];}, $command['devices']);
            $handledDeviceIds = array_merge($handledDeviceIds, $deviceIds);
            foreach($command['execution'] as $exec){
                
                //This code is executed for each device block

                $trait;             //trait that is requested
                $value;             //value that this trait gets

                if($exec['command'] === 'action.devices.commands.OnOff'){
                    $trait = 'onoff';
                    $value = $exec['params']['on'] ? "1":"0";
                }elseif($exec['command'] === 'action.devices.commands.BrightnessAbsolute'){
                    $trait = 'brightness';
                    $value = $exec['params']['brightness'];
                }elseif($exec['command'] === 'action.devices.commands.ActivateScene'){
                    $trait = 'scene';
                    $value = 1;
                }elseif($exec['command'] === 'action.devices.commands.ThermostatTemperatureSetpoint'){
                    $trait = 'tempset.setpoint';
                    $value = $exec['params']['thermostatTemperatureSetpoint'];
                }elseif($exec['command'] === 'action.devices.commands.ThermostatSetMode'){
                    $trait = 'tempset.mode';
                    $value = $exec['params']['thermostatMode'];
                }else{
                    //unknown execute-command
                    continue;
                }

                foreach($deviceIds as $deviceid){
                    //publish the new state to Redis
                    Redis::publish("gbridge:u$user->user_id:d$deviceid:$trait", $value);

                    //do not add to successfull devices if marked offline
                    $powerstate = Redis::hget("gbridge:u$user->user_id:d$deviceid", 'power');
                    if(is_null($powerstate)){
                        $successfulDeviceIds[] = $deviceid;
                    }elseif($powerstate != '0'){
                        $successfulDeviceIds[] = $deviceid;
                    }
                }
            }
        }

        $handledDeviceIds = array_unique($handledDeviceIds);
        $successfulDeviceIds = array_unique($successfulDeviceIds);

        $response = [
            'requestId' => $requestid,
            'payload' => [
                'commands' => []
            ]
        ];

        if(count($successfulDeviceIds) > 0){
            $response['payload']['commands'][] = [
                'ids' => $successfulDeviceIds,
                'status' => 'SUCCESS'
            ];
        }
        if(count(array_diff($handledDeviceIds, $successfulDeviceIds)) > 0){
            $response['payload']['commands'][] = [
                'ids' => array_values(array_diff($handledDeviceIds, $successfulDeviceIds)),
                'status' => 'OFFLINE'
            ];
        }
       
        return response()->json($response);
    }

    /**
     * Send an error message back
     */
    private function errorResponse($requestid, $errorcode){
        $error = [
            'requestId' => $requestid,
            'payload' => [
                'errorCode' => $errorcode   
            ]
        ];

        return response()->json($error);
    }
}

//error codes that can be returned
abstract class ErrorCode{
    const authExpired = "authExpired";
    const authFailure = "authFailure";
    const deviceOffline = "deviceOffline";
    const timeout = "timeout";
    const deviceTurnedOff = "deviceTurnedOff";
    const deviceNotFound = "deviceNotFound";
    const valueOutOfRange = "valueOutOfRange";
    const notSupported = "notSupported";
    const protocolError = "protocolError";
    const unknownError = "unknownError";
}