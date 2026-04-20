import {
  Banner,
  BlockStack,
  Spinner,
  Text,
  reactExtension,
  useApi,
  useOrder,
} from "@shopify/ui-extensions-react/customer-account";
import {useEffect, useState} from "react";

export default reactExtension(
  "customer-account.order-status.block.render",
  () => <OrderProgressBlock />
);

/**
 * IMPORTANT:
 * Set this to your deployed Laravel app URL (no trailing slash).
 * Staging: staging host. Production: https://aganbarzel.co.il
 */
const API_BASE_URL = "https://staging.aganbarzel.co.il";

function OrderProgressBlock() {
  const api = useApi();
  const order = useOrder();
  const inCheckoutEditor = api.extension?.editor?.type === "checkout";
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [payload, setPayload] = useState(null);

  useEffect(() => {
    let mounted = true;

    async function run() {
      if (inCheckoutEditor) {
        return;
      }

      if (order === undefined) {
        return;
      }

      const orderId = order?.id ? String(order.id) : "";
      if (!orderId) {
        if (mounted) {
          setLoading(false);
          setError("Unable to resolve order ID from customer account context.");
        }
        return;
      }

      setLoading(true);
      setError("");

      try {
        const token = await api.sessionToken.get();
        if (!token) {
          throw new Error("Missing Shopify customer session token.");
        }

        const url =
          API_BASE_URL +
          "/api/customer-account/order-progress?order_id=" +
          encodeURIComponent(orderId);

        const res = await fetch(url, {
          method: "GET",
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: "application/json",
          },
        });

        const body = await res.json().catch(() => ({}));
        if (!res.ok) {
          throw new Error(body.error || "Failed to load order tags.");
        }

        if (mounted) {
          setPayload(body);
        }
      } catch (e) {
        if (mounted) {
          setError(e?.message || "Failed to load order tags.");
        }
      } finally {
        if (mounted) {
          setLoading(false);
        }
      }
    }

    run();
    return () => {
      mounted = false;
    };
  }, [api, order, inCheckoutEditor]);

  if (inCheckoutEditor) {
    return (
      <BlockStack spacing="tight">
        <Text emphasis="bold">Order tags</Text>
        <Banner status="info" title="Editor preview">
          <Text>
            Tags load on the real customer order page after Save. This preview
            does not call your app API.
          </Text>
        </Banner>
      </BlockStack>
    );
  }

  if (loading) {
    return (
      <BlockStack spacing="tight">
        <Text emphasis="bold">Order tags</Text>
        <Spinner accessibilityLabel="Loading order tags" />
      </BlockStack>
    );
  }

  if (error) {
    return (
      <Banner status="critical" title="Order tags unavailable">
        <Text>{error}</Text>
      </Banner>
    );
  }

  const orderTags = Array.isArray(payload?.order_tags) ? payload.order_tags : [];

  return (
    <BlockStack spacing="tight">
      <Text emphasis="bold">Order tags</Text>
      <Text>
        From Shopify Admin (this order). Anything staff adds as a tag appears
        here automatically.
      </Text>

      {payload?.cancelled_at ? (
        <Banner status="warning" title="Order cancelled">
          <Text>This order was cancelled.</Text>
        </Banner>
      ) : null}

      {orderTags.length > 0 ? (
        <BlockStack spacing="extraTight">
          {orderTags.map((tag) => (
            <Text key={tag}>• {tag}</Text>
          ))}
        </BlockStack>
      ) : (
        <Text>No tags on this order yet.</Text>
      )}
    </BlockStack>
  );
}
