<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\User\SignUpRequest;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Repositories\Interfaces\Admin\SellerProfileInterface;
use App\Traits\SendMailTrait;
use App\Traits\SendNotification;
use App\Traits\SmsSenderTrait;
use App\Utility\AppSettingUtility;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Cartalyst\Sentinel\Laravel\Facades\Activation;
use App\Http\Controllers\Controller;
use App\Traits\ImageTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Sentinel;


class RegisterController extends Controller
{
    use ImageTrait;
    use SmsSenderTrait,SendMailTrait,SendNotification;

    public function register()
    {

        return view('admin.authenticate.register');
    }

    public function postRegister(SignUpRequest $request, SellerProfileInterface $seller)
    {
        DB::beginTransaction();
        try {
            $data = $request->all();
            if (!blank($request->file('license'))) {
                $requestImage   = $request->file('license');
                $license_response = $this->saveFile($requestImage, '_file_');
                $data['license'] = $license_response;
            }
    
            if (!blank($request->file('vat'))) {
                $requestImage   = $request->file('vat');
                $vat_response = $this->saveFile($requestImage, '_file_');
                $data['vat'] = $vat_response??'';
            }
            $data['user_type'] = $request->user_type == 'company'? 'company':'customer';
            if ($request->phone) {
                $request['phone'] = str_replace(' ','',$request->phone);
                $req = RegistrationRequest::where('phone',$request->phone)->first();

                if (!$req)
                {
                    return response()->json([
                        'error' => __('Verification Code Needed To Verify')
                    ]);
                }

                if ($request->otp != $req->otp) {
                    return response()->json([
                        'error' => __('OTP Doesnt Match')
                    ]);
                }
                RegistrationRequest::where('phone',$request->phone)->delete();
                $request['password'] = '123456';
                
                $sellerData = Sentinel::registerAndActivate($data);
            }
            
            if (!$request->phone) {
                if($request->user_type == 'customer'){
                    $data['status'] = 1;
                }else{
                    $data['status'] = 0;
                    $data['accept_cod'] = 1;
                }
                    $sellerData = Sentinel::register($data);
                    $activation = Activation::create($sellerData);
                // } else {
                //     $sellerData = Sentinel::registerAndActivate($data);
                // }
            }
            if ($request->email) {
//                    $this->sendMail($sellerData, $activation->code, 'verify_email');
                // if($request->user_type == 'company'){
                    $this->sendmail($request->email, 'Registration', $sellerData, 'email.auth.activate-account-email',url('/') . '/activation/' . $request->email . '/' . $activation->code);
                // } 
                if($sellerData){
                    $user_id = $sellerData->id;
                    $sellerData['id'] = 1;
                    $this->SendNotification($sellerData, __("{$sellerData->first_name} {$sellerData->last_name} has been registered"),'success','edit-customer/'.$user_id);
                }
            }
            else if ($request->phone){
                $user = User::where('phone',$request->phone)->first();
                Sentinel::login($user);
            }


            $request['user_id'] = $sellerData->id;
            if ($request->user_type == 'seller') {
                $data = [
                    'seller' => $seller->store($request),
                ];
                Sentinel::logout();
                session()->flush();
                session()->regenerate();
            }

            DB::commit();

            return response()->json([
                'success' => $request->email ?  __('Check your mail to verify your account') : __('Registration Successfully'),
                'migrate_msg' => __('Request sent successfully. Wait for approval.'),
                'user' => $sellerData,
                'auth_user' => authUser(),
                'type' => $request->email ? 0 : 1,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => $e->getMessage()
            ]);
        }
    }

    public function sellerRegister(SignUpRequest $request, SellerProfileInterface $seller)
    {
        DB::beginTransaction();
        try {
            $request['phone'] = str_replace(' ','',$request->phone);

            $sellerData = Sentinel::register($request->all());

            $activation = Activation::create($sellerData);

            try {
                $otp = rand(10000, 99999);
                if ($request->phone && addon_is_activated('otp_system')):
                    $sms_templates = AppSettingUtility::smsTemplates();
                    $sms_template = $sms_templates->where('tab_key', 'signup')->first();
                    $sms_body = str_replace('{otp}', $otp, @$sms_template->sms_body);
                    if ($this->send($request->phone, $sms_body, @$sms_template->template_id)):
                        $sellerData->otp = $otp;
                        $sellerData->save();
                    endif;
                endif;
            } catch (\Exception $e) {
                Toastr::error(__('Please check your email configuration'));
                DB::rollback();
                return false;
            }
            $request['user_id'] = $sellerData->id;
            if ($request->user_type == 'seller') {
                $data = [
                    'seller' => $seller->store($request),
                ];
                Sentinel::logout();
                session()->flush();
                session()->regenerate();
            }
            DB::commit();
            if ($request->email) {
//                sendMail($sellerData, $activation->code, 'verify_email', $otp);
                $this->sendmail($request->email, 'Registration', $sellerData, 'email.auth.activate-account-email',url('/') . '/activation/' . $request->email . '/' . $activation->code);

            }
            return response()->json([
                'success' => __('Check your mail to verify your account'),
                'migrate_msg' => __('Registration is successful, Wait for the Approval'),
                'user' => $sellerData
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' =>$e->getMessage()
            ]);
        }
    }

    public function registerByPhone(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'first_name'    => 'required',
            'last_name'     => 'required',
            'phone'         => 'required',
        ]);
        try {
            $request['phone'] = str_replace(' ','',$request->phone);

            $req = RegistrationRequest::where('phone',$request->phone)->first();

            if ($req && Carbon::parse($req->created_at)->addMinutes(2) >= Carbon::now())
            {
                return response()->json([
                    'error' => __('Verification Code was Already Sent')
                ]);
            }

            $user = User::where('phone', $request->phone)->first();
            RegistrationRequest::where('phone',$request->phone)->delete();

            if ($user) {
                return response()->json([
                    'error' => __('This Phone Number is Already Registered')
                ]);
            }
            $otp = rand(10000, 99999);
           if ($request->phone && addon_is_activated('otp_system')):
                $sms_templates  = AppSettingUtility::smsTemplates();
                $sms_template   = $sms_templates->where('tab_key','signup')->first();
                $sms_body       = str_replace('{otp}', $otp, @$sms_template->sms_body);
                if (!$this->send($request->phone, $sms_body, $sms_template->template_id)):
                    return response()->json([
                        'error' => __('Unable to send otp')
                    ]);
                endif;
            endif;
            $request['otp'] = $otp;
            RegistrationRequest::create($request->all());
            return response()->json([
                'data' => __('OTP Send Successfully')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ]);
        }
    }
}
