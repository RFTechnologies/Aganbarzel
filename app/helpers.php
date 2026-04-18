<?php

use App\Models\Tag;

function delete_products($tag_name,$shop){
    $tag = Tag::where('name',$tag_name)->first();
    $request = $shop->api()->graph('{products(first: 50, query: "tag:'.$tag_name.'"){edges {node {id}}}}');
    $products = $request['body']['data']['products']['edges'];
    $products = $products->toArray();
    $ids = [];
    foreach($products as $product){
        $id = $product['node']['id'];
        $request = $shop->api()->graph('mutation {productDelete(input: {id: "'.$id.'"}){deletedProductId}}');
        array_push($ids,$id);
    }
    $tag->delete_date = null;
    $tag->save();
    return $tag_name.' deleted successfully!';
}


// Delete unused variants

    function getOpenOrderIds($shop){
        $orders = $shop->api()->rest('GET', '/admin/api/2022-04/orders.json',[
            'query' => [
                'status'=> 'open',
                'fields' => 'id,line_items'
            ]
        ]);
        $ids = [];
        foreach ($orders['body']['orders'] as $order) {
            foreach ($order['line_items'] as $line_item) {
                array_push($ids,$line_item['variant_id']);
            }
        }
        $ids = array_unique($ids);
        return $ids; 
    }

    function getClosedOrderIds($shop){
        $orders = $shop->api()->rest('GET', '/admin/api/2022-04/orders.json',[
            'query' => [
                'status'=> 'closed',
                'fields' => 'id,line_items',
                'limit' => 10
            ]
        ]);
        $ids = [];
        foreach ($orders['body']['orders'] as $order) {
            foreach ($order['line_items'] as $line_item) {
                array_push($ids,$line_item['variant_id']);
            }
        }
        $ids = array_unique($ids);
        return $ids; 
    }

    function getVariantsIds($shop){
        $tag = 'product-with-calculator';
        $product = $shop->api()->graph('{products(first: 50, reverse: true, query: "tag:'.$tag.'"){edges {node {id}}}}');

        $body = $product['body'] ?? null;
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        if ($data === null || !empty($data['errors'] ?? [])) {
            return ['ids' => [], 'variants_objs' => []];
        }
        $productsNode = $data['products'] ?? null;
        if ($productsNode === null || !isset($productsNode['edges'])) {
            return ['ids' => [], 'variants_objs' => []];
        }
        $edges = $productsNode['edges'];
        if (is_object($edges) && method_exists($edges, 'toArray')) {
            $edges = $edges->toArray();
        }
        if (!is_array($edges)) {
            return ['ids' => [], 'variants_objs' => []];
        }

        $products = (array) $edges;
        $container = $products['container'] ?? $edges;
        if (!is_array($container)) {
            return ['ids' => [], 'variants_objs' => []];
        }

        $size = count($container);
        $ids = [];
        $ids_dates_obj = [];
        if($size > 0){
            foreach ($container as $item) {
                $product_id = str_replace("gid://shopify/Product/","",$item['node']['id']);
                $variants = $shop->api()->rest('GET', '/admin/api/2022-04/products/'.$product_id.'/variants.json',[
                    'fields' => 'id,created_at,product_id,image_id',
                    'limit' => 200
                ]);
                foreach ($variants['body']['variants'] as $variant) {
                    array_push($ids, $variant['id']);
                    array_push($ids_dates_obj,[
                        'id' => $variant['id'],
                        'image_id' => $variant['image_id'],
                        'created_at' => $variant['created_at'],
                        'product_id' => $variant['product_id']
                    ]);
                }
            }
        }
        return ['ids' => $ids, 'variants_objs' => $ids_dates_obj];
    }

    function deleteVariant($shop,$id, $product_id,$image_id){
        $variants = $shop->api()->rest('DELETE', '/admin/api/2022-04/products/'.$product_id.'/variants/'.$id.'.json');
        if($image_id != null){
            $variant_img = $shop->api()->rest('DELETE','/admin/api/2022-04/products/'.$product_id.'/images/'.$image_id.'.json');
        }
    }

    function delete_unused_variants($shop){
        $variant_ids_objs = getVariantsIds($shop);
        $open_ids = getOpenOrderIds($shop);
        $closed_ids = getClosedOrderIds($shop);
        $open_variants = [];
        foreach ($variant_ids_objs['ids'] as $id) {

            $variant_id_obj = null;
            foreach ($variant_ids_objs['variants_objs'] as $value) {
                if($value['id'] == $id){
                    $variant_id_obj = $value;
                }
            }

            if(in_array($id, $open_ids)){
                array_push($open_variants,$id);
            }elseif(in_array($id, $closed_ids)){
                //Delete these variants
                deleteVariant($shop,$id,$variant_id_obj['product_id'],$variant_id_obj['image_id']);
            }else{
                $date = strtotime($variant_id_obj['created_at']);
                if(time() - $date > 24 * 60 * 60){
                    // Delete These variants
                    deleteVariant($shop,$id,$variant_id_obj['product_id'],$variant_id_obj['image_id']);
                    $date_result = '2 hours passed';
                }
            }
        }


        return response()->json([
            'status'=> 'success', 
            'open orders' => $open_ids, 
            'closed orders' => $closed_ids,
            'variant IDs>>' => $variant_ids_objs,
            'Are open>>' => $open_variants
        ]);
    }