<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Ad;
use App\Hashtag;
use Image;
use JWTAuth;
use App\Http\Resources\Ads as AdResource;
use DB;
use Illuminate\Support\Facades\Crypt;
use App\HashtagSubscriber;
use App\Notification;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;





class AdController extends Controller
{
    public $image_width = '800' ;
    public $image_height = '800' ;
    public $token = '1200' ;


    public function __construct(Request $request)
    {
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $requestData = $request->all();
        $where = [
            ['ad_status','=', 'A'],
            ['updated_at','>=',Carbon::now()->subDays(100)->toDateTimeString()]
        ];

        $query = '';

        if(count($requestData) > 0){
            
            $city = $requestData['city'];          
            if($city != '')
            {
                $query .= "(`city` = '".$city."' or `city` IS NULL) ";
            }
        }

        $hashtags = $requestData['hashtags'];
        if(count($hashtags) > 0)
        {
        $query .= $this->searchHashtagFormation($hashtags);
        }

        $ads= Ad::where($where);

        if($query != '')
        $ads->whereRaw($query);

        if(isset($requestData['sort_by']))
        {
            switch($requestData['sort_by'])
            {
                case 'views':
                    $ads->orderBy('views', 'desc');
                break;
                case 'likes':
                    $ads->orderBy('favCount', 'desc');
                break;
                case 'latest':
                    $ads->orderBy('updated_at', 'desc');
                break;
                default:
                    $ads->orderBy('updated_at', 'desc');
                break;
            }
        }


        return AdResource::collection($ads->paginate(15));
    }

    public function userBasedList(Request $request)
    {
        $requestData = $request->all();

        $this->token = $request->header('Authorization');
    
        if ($this->token != '') {  
            $user = JWTAuth::toUser($this->token);    
            $where = [
               ['user_id','!=', $user['id']],
                ['ad_status','=', 'A'],
                ['updated_at','>=',Carbon::now()->subDays(100)->toDateTimeString()]
            ];
        }
    
        $query = '';

        if(count($requestData) > 0){
            
            $city = $requestData['city'];                    

            if($city != '')
            {
                $query .= "(`city` = '".$city."' or `city` IS NULL) ";
            }
        }
        $hashtags = $requestData['hashtags'];
        if(count($hashtags) > 0)
        {
        $query .= $this->searchHashtagFormation($hashtags);
        }

        $ads= Ad::where($where);

        if($query != '')
        $ads->whereRaw($query);
        
        if(isset($requestData['sort_by']))
        {
            switch($requestData['sort_by'])
            {
                case 'views':
                    $ads->orderBy('views', 'desc');
                break;
                case 'likes':
                    $ads->orderBy('favCount', 'desc');
                break;
                case 'latest':
                    $ads->orderBy('updated_at', 'desc');
                break;
                default:
                    $ads->orderBy('updated_at', 'desc');
                break;
            }
        }


        return AdResource::collection($ads->paginate(15));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $validatedData = $request->validate([
            'adtextarea' => 'required|max:255',
            'city' => 'nullable|max:50',
            'websitelink' =>'nullable|url|max:255'
        ]);
       
        $requestData = $request->all();
        $this->token = $request->header('Authorization');
        $user = JWTAuth::toUser($this->token);    
        $requestData['user_id']=$user['id'];
        $requestData['ad_text'] = $request->input('adtextarea');
        $requestData['show_text'] = $request->input('adtextarea');
        $coordinates = $request->input('coordinates');
        $requestData['coordinates'] = implode(",",$coordinates);

        $base64_str=$request->input('adImages');
    
        $requestData['ad_image_1'] = $this->base64ImageUpload((isset($base64_str[0])?$base64_str[0]:''),'ad_image_1');
        $requestData['ad_image_2'] = $this->base64ImageUpload((isset($base64_str[1])?$base64_str[1]:''),'ad_image_2');
        $requestData['ad_image_3'] = $this->base64ImageUpload((isset($base64_str[2])?$base64_str[2]:''),'ad_image_3');
        $requestData['ad_image_4'] = $this->base64ImageUpload((isset($base64_str[3])?$base64_str[3]:''),'ad_image_4');
        $hashtagExtraction = $this->extractHashtags($requestData['adtextarea'],1);
        $requestData['show_text'] = $hashtagExtraction['ad_text'];
        $requestData['hashtags'] = $hashtagExtraction['hashtags'];  
        $subscribers = $hashtagExtraction['subscribers'];
        
        if($requestData['hashtags'] == '')
        return response(['message'=>'Atleast one hashtag is required'], 411);

        unset($requestData['adtextarea']);

        $ad = Ad::create($requestData);

        foreach($subscribers as $subs)
        {
            Notification::create([
                'user_id'=>$requestData['user_id'],
                'ad_id'=>$ad->id,
                'notify_user'=>$subs->user_id,
                'notification_type'=>5,
                'hashtag_id'=>$subs->hashtag_id
            ]);
        }


        return ['message' => 'Ad Created!'];
 
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
        $decryptedID = Crypt::decryptString($id);

        $ad = Ad::findOrfail($decryptedID);
        return new AdResource($ad);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request,$id)
    {
        $validatedData = $request->validate([
            'adtextarea' => 'required|max:255',
            'city' => 'nullable|max:50',
            'websitelink' =>'nullable|url|max:255'
        ]);
       
        $requestData = $request->all();
        $decryptedID = Crypt::decryptString($id);

        $ad = Ad::findOrFail($decryptedID);
        $this->token = $request->header('Authorization');
        $user = JWTAuth::toUser($this->token);    
        $requestData['user_id']=$user['id'];
        $requestData['ad_text'] = $request->input('adtextarea');
        $requestData['show_text'] = $request->input('adtextarea');
        $coordinates = $request->input('coordinates');
        $requestData['coordinates'] = implode(",",$coordinates);

        $base64_str=$request->input('adImages');
    
        $requestData['ad_image_1'] = $this->base64ImageUpload((isset($base64_str[0])?$base64_str[0]:''),'ad_image_1');
        if($requestData['ad_image_1'] == '')
        {
            unset($requestData['ad_image_1']);
        }
        $requestData['ad_image_2'] = $this->base64ImageUpload((isset($base64_str[1])?$base64_str[1]:''),'ad_image_2');
        if($requestData['ad_image_2'] == '')
        {
            unset($requestData['ad_image_2']);
        }
        $requestData['ad_image_3'] = $this->base64ImageUpload((isset($base64_str[2])?$base64_str[2]:''),'ad_image_3');
        if($requestData['ad_image_3'] == '')
        {
            unset($requestData['ad_image_3']);
        }
        $requestData['ad_image_4'] = $this->base64ImageUpload((isset($base64_str[3])?$base64_str[3]:''),'ad_image_4');
        $hashtagExtraction = $this->extractHashtags($requestData['adtextarea'],1);
        $requestData['show_text'] = $hashtagExtraction['ad_text'];
        $requestData['hashtags'] = $hashtagExtraction['hashtags'];

        if($requestData['hashtags'] == '')
        return response(['message'=>'Atleast one hashtag is required'], 411);

        if($requestData['ad_image_4'] == '')
        {
            unset($requestData['ad_image_4']);
        }
        unset($requestData['adtextarea']);
        $ad->update($requestData);
        return ['message' => 'Ad updated!'];
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    public function updateViewCount(Request $request)
    {
        $requested_data = $request->all(); 

        $decryptedID = Crypt::decryptString($requested_data['adid']);

        $user = JWTAuth::User();
        $id = $user['id'];
        $validator=$request->validate([
            "adid" => 'required'
        ]);
        $user=DB::table('ads')->where('id', '=', $decryptedID)->increment('views');
        return $user;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
        $decryptedID = Crypt::decryptString($id);

        Ad::destroy($decryptedID);
    }

    public function base64ImageUpload($base64_str,$image_string)
    {
        if($base64_str != '' && !(filter_var($base64_str, FILTER_VALIDATE_URL)))
        {
            $image = base64_decode($base64_str);
            $png_url = uniqid('AD-').time().".png";
            $path = public_path() . "/uploads/ad/" . $png_url;
            
            $img = Image::make($base64_str);
            $img->resize($this->image_width, $this->image_height, function ($constraint) {
            $constraint->aspectRatio();
            });
            $img->resizeCanvas($this->image_width, $this->image_height); 
            if(env('APP_ENV') != 'local')
            {
                $resized_image = $img->stream('png', 100);
                Storage::disk('s3')->put('ads/'.$png_url,$resized_image);
            }
            else
            {
                $img->save($path);
            }
        }
        else {
         $png_url = '';
        }

        return $png_url;
    }

    public function userAds(Request $request)
    {
        $requestData = $request->all();
    
        if(isset($requestData['userid']))
        {
            $decryptedID = Crypt::decryptString($requestData['userid']);

            $where = [
                ['user_id','=', $decryptedID],
            ];  
        }
        else {
            $user = JWTAuth::User();    
            $where = [
                ['user_id','=', $user['id']],
            ];
        }
      
        $ads= Ad::where($where)->orderBy('updated_at', 'desc')->paginate(6);

        return AdResource::collection($ads);
    }

    public function fetch_jwt_details($token)
    {   
        return JWTAuth::toUser($token)->toArray();
    }

    public function extractHashtags($adtextarea,$edit=0)
    {
        preg_match_all('/#(\w+)/', $adtextarea, $matches);
        $adText = $adtextarea;
        $requestData['show_text'] = '';
        foreach ($matches[0] as $hashtag_name) {
         $adText = str_replace($hashtag_name,
            "<span class='hashtags'>".$hashtag_name."</span>",$adText);
        }
        foreach ($matches[1] as $hashtag_name) {
           $checkHashtag = Hashtag::where('hashtag', '=', $hashtag_name)->get();
           //print_r($checkHashtag);
           if(count($checkHashtag) == 0)
           {
            Hashtag::create(['hashtag' => $hashtag_name,'count'=>1]);
            $subscribers=0;
           } 
           else
           {
           $subscribers = HashtagSubscriber::where('hashtag_id','=',$checkHashtag[0]->id)->get();
           
            if($edit == 0)
            DB::table('hashtags')->where('hashtag', '=', $hashtag_name)->increment('count');

           }


        }
        return ['ad_text'=>$adText,'hashtags'=>implode(",",$matches[1]),'subscribers'=>$subscribers];
    }

    public function searchHashtagFormation($hashtags)
    {
        $hashtagsQuery  = '';
        $countHashtags =count($hashtags);
        foreach ($hashtags as $key => $ht) {
            $hashtagsQuery .= 'FIND_IN_SET("'.$ht.'",`hashtags`)';
            if($countHashtags != $key+1)
            {
                $hashtagsQuery .= " or ";
            }
        }

        return $hashtagsQuery;
    }
}
