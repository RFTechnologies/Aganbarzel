<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Models\Tag;
use App\Models\User;
use ProductsHelper;

use Carbon\Carbon;
use DateTime;

class DeleteProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    // public function handle()
    // {
    //     $now = new DateTime('now');
    //     info('run at '.Carbon::parse($now)->diffForhumans());
    //     $tags = Tag::all();
    //     foreach ($tags as $tag) {
    //         $shop = User::where('id', $tag->shop_id)->first();
    //         if($tag->delete_date){
    //             $delete_date =new DateTime($tag->delete_date);
    //             if($now > $delete_date){
    //                 delete_products($tag->name,$shop);
    //             }
    //         }
    //     }
    // }
    
    public function handle(){
        
        $shop = User::where('name','rftechnologies-teams.myshopify.com')->first();
        delete_unused_variants($shop);
        info('Running unused variant delete job');
        
    }
}
