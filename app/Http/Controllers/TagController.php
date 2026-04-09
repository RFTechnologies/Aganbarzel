<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Tag;
use Validator;
use Carbon\Carbon;

class TagController extends Controller
{
    public function index(){
        $tags  = Tag::all();
        return response()->json(['status' => 'success', 'items' => $tags]);
    }
    public function add(Request $request){
        $shop = Auth::user();
        $validator = Validator::make($request->all(),[
            'name' => 'required|unique:tags'
        ]);
        if($validator->fails()){
            return response()->json(['status'=>'fails', 'message' => 'Tag is required and should be unique.', 'tag' => $request->name]);
        }
        $tag = new Tag();
        $tag->name = $request->name;
        $tag->shop_id = $shop->id;
        $tag->save();
        $tags = Tag::all();
        return response()->json(['status'=>'success', 'items' => $tags,'tag' => $request->name]);
    }

    public function delete($id){
        $tag = Tag::where('id', $id)->first();
        $tag->delete();
        $tags = Tag::all();
        return response()->json(['status'=>'success', 'items' => $tags]);
    }

    public function deleteProducts($id){
        $shop = Auth::user();
        $tag = Tag::where('id',$id)->first();
        $deleted = delete_products($tag->name,$shop);
        return response()->json(['status' => 'success', 'message' => $deleted]);
    }

    public function updateDate(Request $request){
        $tag = Tag::where('id', $request->id)->first();
        $date = Carbon::parse($request->date);
        $tag->delete_date = $date;
        $tag->save();
        return response()->json(['status' => 'success', 'message' => 'Date updated for "'.$tag->name.'"', 'testing' => $date]);
    }
}
