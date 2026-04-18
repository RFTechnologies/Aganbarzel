import {
  Banner,
  BlockStack,
  Spinner,
  Text,
  reactExtension,
  useApi,
} from "@shopify/ui-extensions-react/customer-account";
import {useEffect, useState} from "react";

export default reactExtension(
  "customer-account.order-status.block.render",
  () => <OrderProgressBlock />
);

/**
 * IMPORTANT:
 * Set this to your deployed Laravel app URL (no trailing slash).
 * Staging (staging_rf / testing): use staging host. Production: use https://aganbarzel.co.il
 */
const API_BASE_URL = "https://staging.aganbarzel.co.il";

function OrderProgressBlock() {
  const api = useApi();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [payload, setPayload] = useState(null);

  useEffect(() => {
    let mounted = true;

    async function run() {
      setLoading(true);
      setError("");

      try {
        const orderId = extractOrderId(api);
        if (!orderId) {
          throw new Error("Unable to resolve order ID from customer account context.");
        }

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
          throw new Error(body.error || "Failed to load order progress.");
        }

        if (mounted) {
          setPayload(body);
        }
      } catch (e) {
        if (mounted) {
          setError(e?.message || "Failed to load order progress.");
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
  }, [api]);

  if (loading) {
    return (
      <BlockStack spacing="tight">
        <Text emphasis="bold">Order progress</Text>
        <Spinner accessibilityLabel="Loading order progress" />
      </BlockStack>
    );
  }

  if (error) {
    return (
      <Banner status="critical" title="Order progress unavailable">
        <Text>{error}</Text>
      </Banner>
    );
  }

  const steps = payload?.steps ?? [];

  return (
    <BlockStack spacing="tight">
      <Text emphasis="bold">Order progress</Text>

      {payload?.payment_message_he ? (
        <Banner status="warning" title="Payment action required">
          <Text>{payload.payment_message_he}</Text>
        </Banner>
      ) : null}

      {payload?.eta_summary_he ? <Text>{payload.eta_summary_he}</Text> : null}

      <BlockStack spacing="extraTight">
        {steps.map((step) => (
          <Text key={step.key}>
            {step.done ? "✓ " : "… "}
            {step.label_he}
          </Text>
        ))}
      </BlockStack>
    </BlockStack>
  );
}

function extractOrderId(api) {
  const target = api?.target || {};

  const candidates = [
    target?.order?.id,
    target?.orderId,
    target?.id,
    api?.order?.id,
  ];

  for (const c of candidates) {
    if (!c) continue;
    const value = String(c);
    if (value) return value;
  }

  return "";
}
