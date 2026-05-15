<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Tag;

class ProductController extends Controller
{
    public function count(){
        $shop = Auth::user();
        $request = $shop->api()->rest('GET', '/admin/api/2022-04/products.json', ['query' => "tag:rf-calculator"]);
        $request = $shop->api()->graph('{products(first: 50, query: "tag:rf-calculator"){edges {node {title}}}}');
        $products = $request['body']['data']['products']['edges'];
        if($products){
            return response()->json(['status'=>200,'data'=>$products->count()]);
        }else{
            return response()->json(['status'=>200,'data'=>0]);
        }
    }

    public function delete(){
        $shop = Auth::user();
        $request = $shop->api()->graph('{products(first: 50, query: "tag:rf-calculator"){edges {node {id}}}}');
        $products = $request['body']['data']['products']['edges'];
        $products = $products->toArray();
        $ids = [];
        foreach($products as $product){
            $id = $product['node']['id'];
            $request = $shop->api()->graph('mutation {productDelete(input: {id: "'.$id.'"}){deletedProductId}}');
            array_push($ids,$id);
        }
        return response()->json(['status'=>200,'data'=>$request]);
    }

    public function create(Request $request){
        $body = $request->getContent();
        $body = json_decode($body,true);
        $title = $body['title'];
        $price = $body['price'];
        $tag = $body['tag'];
        $image = $body['image'];
        if(isset($body['shop'])){
            $user = User::where('name',$body['shop'])->first();
            $variants= [];
            $images=[];
            $status = 'Product Not Created';
            array_push($variants, [
                "option1" => 'price',
                "price"=> $price,
                "inventory_quantity" => '1',
            ]);
            array_push($images, [
                "src" => $image,
            ]);
            $productJson=[
                "title"=>isset($title)?$title:'Test Product',
                "published"=>true,
                "status"=>'active',
                "tags"=>$tag,
                "variants"=> $variants,
                "images"=> $images
            ];

            $mainProduct = $user->api()->rest('POST','/admin/products.json',[
                'product'=>$productJson
            ]);
            if ($mainProduct['errors']==false){
                $status='Product Created';
            }
        }
        $data = array(
            'request_data'=>$request->all(),
            'user'=>$user,
            'mainProduct'=>$mainProduct,
            'status'=>$status
        );
        return response()->json($data);
    }

    
    public function getVariant(Request $request){
        $title = $request->title.' - '.date("H:i:s_d/m/Y", time() + 60*60*3);
        $price = $request->price;
        $tag = $request->tag;
        $image = $request->image;
        $weight = $request->weight;
        $weight_unit = $request->weight_unit;
        $sku = $request->sku;
        $requires_shipping = $request->requires_shipping;
        $shipping_profile = $request->shipping_profile;
        $variant = null;
        $variant_count = null;
        $product_id = null;
        $image_id = null;

        try {

            $shop = User::where('name', $request->shop)->first();
            $product = $shop->api()->graph('{products(first: 10, reverse: true, query: "tag:'.$tag.'"){edges {node {id featuredImage{id}}}}}');
            
            $products = (array) $product['body']['data']['products']['edges'];
            $size = count($products['container']);
            if($size > 0){
                $product_id = str_replace("gid://shopify/Product/","",$products['container'][0]['node']['id']);
                $variant_count = $shop->api()->rest('GET', '/admin/api/2022-04/products/'.$product_id.'/variants/count.json');
            }

            if($size <= 0){
                // creaate product and variant and return variant
                $variant = $this->createproduct($title, $price, $tag, $image,$shop);
            }else{
                // Add variants on same product only while count stays below this cap; then create a new product.
                if ($variant_count['body']['count'] < 90) {
                    if($products['container'][0]['node']['featuredImage'] != null){
                        $uploadedImage = $this->upload_product_image($image,$product_id,$shop);
                        $image_id = $uploadedImage['body']['image']['id'];
                    }
                    $variant = $this->createVariant($product_id, $image_id, $title, $price, $shop, $weight, $weight_unit, $requires_shipping,$sku);
                }else{
                    $variant = $this->createproduct($title, $price, $tag, $image, $shop, $weight, $weight_unit, $requires_shipping,$sku);
                }
            }
            $variant_id = $variant['body']['variant']['id'];
            if($shipping_profile != null && $variant_id != null){
                $this->setupVariantShippingProfile($shop,$variant_id,$shipping_profile);
            }
            return response()->json([
                'status'=> 'success', 
                'Result' => $products, 
                'count' => $size, 
                'variant' => $variant, 
                'variant count' => $variant_count,
                'product_id' => $product_id
            ]);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json([
                'status' => 'error', 
                'message' => 'Something went wront', 
                'description' => $th->getMessage()
            ]);
            echo 'Message: '.$th->getMessage();
        }
    }

    public function setupVariantShippingProfile($shop,$variant_id,$shipping_profile){
        $out = new \Symfony\Component\Console\Output\ConsoleOutput();
        $out->writeln('Shipping Profile: ' . $shipping_profile);
        $out->writeln('Variant ID: ' . $variant_id);

        try{
            
            // Fetch the first 10 delivery profiles
            $profiles = $shop->api()->graph('{
                deliveryProfiles(first: 100) {
                    edges {
                        node {
                            id
                            name
                        }
                    }
                }
            }');
            
            // Extract profile data from the GraphQL response
            $profiles_data = $profiles['body']['data']['deliveryProfiles']['edges'];
            
            // Find the matching profile by name
            $matching_profile = null;
            foreach ($profiles_data as $profile) {
                if ($profile['node']['name'] === $shipping_profile) {
                    $matching_profile = $profile['node'];
                    break;
                }
            }
            
            // Check if the matching profile is found
            if ($matching_profile) {
                $profile_id = $matching_profile['id']; // Get the profile ID
            } else {
                return;
            }
            


            // Define the GraphQL mutation
            $mutation = 'mutation deliveryProfileUpdate($id: ID!, $profile: DeliveryProfileInput!) {
                deliveryProfileUpdate(id: $id, profile: $profile) {
                    profile {
                        id
                        name
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }';

            // Prepare the input data for the mutation
            $variables = [
                'id' => $profile_id, // The ID of the delivery profile
                'profile' => [
                    'variantsToAssociate' => ['gid://shopify/ProductVariant/'.$variant_id] // The variant ID to be added to the profile
                ]
            ];

            // Make the API request to update the profile
            $shop->api()->graph($mutation, $variables);
            return;
        } catch (\Throwable $th) {
            echo 'Message: '.$th->getMessage();
            return;
        }
    }

    public function createproduct($title, $price, $tag, $image, $shop, $weight, $weight_unit, $requires_shipping,$sku){
        $images=[];
        $status = 'Product Not Created';
        array_push($images, [
            "src" => $image,
        ]);
        $productJson=[
            "title"=>'אגן ברזל',//Product title which was 'RF product calculator' before
            "published"=>true,
            "status"=>'active',
            "tags"=>$tag,
            "images"=> $images
        ];

        $mainProduct = $shop->api()->rest('POST','/admin/products.json',[
            'product'=>$productJson
        ]);

        if ($mainProduct['errors']==false){
            $status='Product Created';
        }
        $data = array(
            'mainProduct'=>$mainProduct,
            'status'=>$status
        );

        $image_id = $mainProduct['body']['product']['image']['id'];
        $id = $mainProduct['body']['product']['id'];
        return $this->createVariant($id,$image_id,$title,$price,$shop,$weight,$weight_unit,$requires_shipping,$sku);
    }

    public function createVariant($id,$image_id,$title,$price,$shop,$weight,$weight_unit,$requires_shipping,$sku){
        // If SKU has a value, append a small random number
        if (!empty($sku)) {
            $sku .= '-' . rand(1, 9999); // Append a dash and a random number from 1 to 99
        }

        $variant = [
            'variant' => [
                'price' => $price,
                'option1' => $title,
                'image_id' => $image_id,
                'weight' => $weight,
                'weight_unit' => $weight_unit,
                'requires_shipping' => $requires_shipping,
                'sku' => $sku,
                'inventory_policy' => 'continue',
            ]
        ];
        $request = $shop->api()->rest('POST', '/admin/api/2022-04/products/'.$id.'/variants.json', $variant);
        return $request;
    }

    public function getProducts(){
        try{
            $shop = Auth::user();
            $tags = Tag::all();
            $items = array();
            foreach ($tags as $tag) {
                $product = $shop->api()->graph('{products(first: 50, reverse: true, query: "tag:'.$tag->name.'"){edges {node {id}}}}');
                $products = (array) $product['body']['data']['products']['edges'];
                $size = count($products['container']);
                $variants_count = 0;
        
                if($size > 0){
                    foreach ($products['container'] as $item) {
                        $product_id = $product_id = str_replace("gid://shopify/Product/","",$item['node']['id']);
                        $variant_count = $shop->api()->rest('GET', '/admin/api/2022-04/products/'.$product_id.'/variants/count.json');
                        $variants_count = $variants_count + $variant_count['body']['count'];
                    }
                    array_push($items,[
                        'id' => $tag->id,
                        'tag' => $tag->name,
                        'delete_date' => $tag->delete_date,
                        'products_size' => $size,
                        'variants_size' => $variants_count
                    ]);
                }
            }
            return response()->json(['status' => 'success', 'items' => $items]);
        } catch (\Throwable $th) {
            return response()->json(['status'=>'fails', 'message' => 'Failed to fetch products. Please retry.', 'error' => $th->getMessage()]);
        }
    }
    public function upload_product_image($image,$id,$shop){
        $uploadedImage = $shop->api()->rest('POST','/admin/api/2022-04/products/'.$id.'/images.json',[
            'image'=>[
                'src' => $image
            ]
        ]);
        return $uploadedImage;
    }
    
    public function updateVariant(Request $request){
        $shop = User::where('name', $request->shop)->first();
        $variant_id = $request->variant_id;
        $title = $request->title.'--'.date("Y-m-d h:i:sa");
        $price = $request->price;
        $variant = $shop->api()->rest('PUT','/admin/api/2022-07/variants/'.$variant_id.'.json',[
            'variant' => [
                'id' => $variant_id,
                'option1' => $title,
                'price' => $price
            ]
        ]);

        return response()->json(['variant' => $variant]);
    }
}
