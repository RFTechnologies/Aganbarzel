<?php

use App\Models\Tag;

/** @param mixed $response */
function normalize_shopify_rest_body($response): array
{
    if (!is_array($response)) {
        return [];
    }
    $body = $response['body'] ?? null;
    if ($body === null) {
        return [];
    }
    if (is_string($body)) {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
    if (is_array($body)) {
        return $body;
    }
    if (is_object($body) && method_exists($body, 'toArray')) {
        $arr = $body->toArray();

        return is_array($arr) ? $arr : [];
    }

    return [];
}

/** @param mixed $body */
function normalize_graphql_body($body): ?array
{
    if (is_string($body)) {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
    if (is_array($body)) {
        return $body;
    }

    return null;
}

function delete_products($tag_name, $shop)
{
    $tag = Tag::where('name', $tag_name)->first();
    if (!$tag) {
        return 'Tag not found: '.$tag_name;
    }

    $request = $shop->api()->graph('{products(first: 50, query: "tag:'.$tag_name.'"){edges {node {id}}}}');
    if (!is_array($request)) {
        return 'GraphQL request failed';
    }

    $body = normalize_graphql_body($request['body'] ?? null);
    if ($body === null) {
        return 'Invalid GraphQL response';
    }

    $data = $body['data'] ?? null;
    if (!is_array($data) || !empty($data['errors'] ?? [])) {
        return 'GraphQL errors or missing data';
    }

    $productsNode = $data['products'] ?? null;
    if ($productsNode === null || !isset($productsNode['edges'])) {
        return 'No products found';
    }

    $edges = $productsNode['edges'];
    if (is_object($edges) && method_exists($edges, 'toArray')) {
        $edges = $edges->toArray();
    }
    if (!is_array($edges)) {
        return 'Invalid products list';
    }

    $edgesArr = (array) $edges;
    $container = $edgesArr['container'] ?? $edges;
    if (!is_array($container)) {
        return 'Invalid products list';
    }

    $ids = [];
    foreach ($container as $product) {
        if (!is_array($product) || !isset($product['node']['id'])) {
            continue;
        }
        $id = $product['node']['id'];
        $shop->api()->graph('mutation {productDelete(input: {id: "'.$id.'"}){deletedProductId}}');
        $ids[] = $id;
    }

    $tag->delete_date = null;
    $tag->save();

    return $tag_name.' deleted successfully!';
}

// Delete unused variants

function getOpenOrderIds($shop)
{
    $orders = $shop->api()->rest('GET', '/admin/api/2022-04/orders.json', [
        'query' => [
            'status' => 'open',
            'fields' => 'id,line_items',
        ],
    ]);
    $body = normalize_shopify_rest_body($orders);
    $orderList = $body['orders'] ?? [];
    if (!is_array($orderList)) {
        return [];
    }

    $ids = [];
    foreach ($orderList as $order) {
        if (!is_array($order)) {
            continue;
        }
        $lineItems = $order['line_items'] ?? [];
        if (!is_array($lineItems)) {
            continue;
        }
        foreach ($lineItems as $line_item) {
            if (!is_array($line_item) || !isset($line_item['variant_id'])) {
                continue;
            }
            $ids[] = $line_item['variant_id'];
        }
    }

    return array_values(array_unique($ids));
}

function getClosedOrderIds($shop)
{
    $orders = $shop->api()->rest('GET', '/admin/api/2022-04/orders.json', [
        'query' => [
            'status' => 'closed',
            'fields' => 'id,line_items',
            'limit' => 10,
        ],
    ]);
    $body = normalize_shopify_rest_body($orders);
    $orderList = $body['orders'] ?? [];
    if (!is_array($orderList)) {
        return [];
    }

    $ids = [];
    foreach ($orderList as $order) {
        if (!is_array($order)) {
            continue;
        }
        $lineItems = $order['line_items'] ?? [];
        if (!is_array($lineItems)) {
            continue;
        }
        foreach ($lineItems as $line_item) {
            if (!is_array($line_item) || !isset($line_item['variant_id'])) {
                continue;
            }
            $ids[] = $line_item['variant_id'];
        }
    }

    return array_values(array_unique($ids));
}

function getVariantsIds($shop)
{
    $tag = 'product-with-calculator';
    $product = $shop->api()->graph('{products(first: 50, reverse: true, query: "tag:'.$tag.'"){edges {node {id}}}}');

    if (!is_array($product)) {
        return ['ids' => [], 'variants_objs' => []];
    }

    $body = normalize_graphql_body($product['body'] ?? null);
    if ($body === null) {
        return ['ids' => [], 'variants_objs' => []];
    }

    $data = $body['data'] ?? null;
    if (!is_array($data) || !empty($data['errors'] ?? [])) {
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

    $ids = [];
    $ids_dates_obj = [];
    foreach ($container as $item) {
        if (!is_array($item) || !isset($item['node']['id'])) {
            continue;
        }
        $product_id = str_replace('gid://shopify/Product/', '', $item['node']['id']);
        $variants = $shop->api()->rest('GET', '/admin/api/2022-04/products/'.$product_id.'/variants.json', [
            'fields' => 'id,created_at,product_id,image_id',
            'limit' => 200,
        ]);
        $variantBody = normalize_shopify_rest_body($variants);
        $variantRows = $variantBody['variants'] ?? null;
        if (!is_array($variantRows)) {
            continue;
        }
        foreach ($variantRows as $variant) {
            if (!is_array($variant) || !isset($variant['id'])) {
                continue;
            }
            $ids[] = $variant['id'];
            $ids_dates_obj[] = [
                'id' => $variant['id'],
                'image_id' => $variant['image_id'] ?? null,
                'created_at' => $variant['created_at'] ?? null,
                'product_id' => $variant['product_id'] ?? null,
            ];
        }
    }

    return ['ids' => $ids, 'variants_objs' => $ids_dates_obj];
}

function deleteVariant($shop, $id, $product_id, $image_id)
{
    $shop->api()->rest('DELETE', '/admin/api/2022-04/products/'.$product_id.'/variants/'.$id.'.json');
    if ($image_id != null) {
        $shop->api()->rest('DELETE', '/admin/api/2022-04/products/'.$product_id.'/images/'.$image_id.'.json');
    }
}

function delete_unused_variants($shop)
{
    $variant_ids_objs = getVariantsIds($shop);
    $open_ids = getOpenOrderIds($shop);
    $closed_ids = getClosedOrderIds($shop);
    $open_variants = [];

    foreach ($variant_ids_objs['ids'] as $id) {
        $variant_id_obj = null;
        foreach ($variant_ids_objs['variants_objs'] as $value) {
            if (isset($value['id']) && $value['id'] == $id) {
                $variant_id_obj = $value;
                break;
            }
        }

        if ($variant_id_obj === null) {
            continue;
        }

        if (in_array($id, $open_ids, true)) {
            $open_variants[] = $id;
        } elseif (in_array($id, $closed_ids, true)) {
            deleteVariant($shop, $id, $variant_id_obj['product_id'], $variant_id_obj['image_id']);
        } else {
            $created = $variant_id_obj['created_at'] ?? '';
            $date = strtotime($created);
            if ($date !== false && (time() - $date) > 24 * 60 * 60) {
                deleteVariant($shop, $id, $variant_id_obj['product_id'], $variant_id_obj['image_id']);
            }
        }
    }

    return response()->json([
        'status' => 'success',
        'open orders' => $open_ids,
        'closed orders' => $closed_ids,
        'variant IDs>>' => $variant_ids_objs,
        'Are open>>' => $open_variants,
    ]);
}
