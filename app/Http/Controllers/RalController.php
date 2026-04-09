<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Ral;

class RalController extends Controller
{
    public function index(){
        $rals  = Ral::all();
        return response()->json(['status' => 'success', 'items' => $rals]);
    }

    public function delete(Request $request){                
        $ids = explode(",", $request->rals);
        //$ids is a Array with the primary keys
        Ral::destroy($ids);
        $rals = Ral::all();
        return response()->json(['status'=>'success', 'items' => $rals]);
    }

    public function add(Request $request){
        $shop = Auth::user();
        try {
            Ral::create([
                'name' => $request->name,
                'price' => $request->price,
                'code' => $request->code,
                'time' => $request->time,
            ]);
            $rals = Ral::all();
            return response()->json(['status'=>'success', 'items' => $rals]);
        } catch (\Throwable $th) {
            return response()->json(['status'=>'fails', 'message' => 'Failed to add RAL. Please retry.', 'error' => $th->getMessage()]);
        }
    }
    
    public function getAll(){
        $rals = Ral::all();
        return response()->json(['status' => 'success', 'items' => $rals]);
        
    }
}
