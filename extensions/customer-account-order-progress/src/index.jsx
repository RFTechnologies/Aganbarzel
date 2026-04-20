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
  () => <OrderProgressBlock />,
);

/**
 * Deployed Laravel app URL (no trailing slash).
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
          setError("לא ניתן לזהות את מספר ההזמנה.");
        }
        return;
      }

      setLoading(true);
      setError("");

      try {
        const token = await api.sessionToken.get();
        if (!token) {
          throw new Error("חסר אסימון סשן של Shopify.");
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
          throw new Error(body.error || "טעינת סטטוס ההזמנה נכשלה.");
        }

        if (mounted) {
          setPayload(body);
        }
      } catch (e) {
        if (mounted) {
          setError(e?.message || "טעינת סטטוס ההזמנה נכשלה.");
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
        <Text emphasis="bold">התקדמות ההזמנה</Text>
        <Banner status="info" title="תצוגת עורך">
          <Text>
            ברגע השמירה, בדף ההזמנה האמיתי יוצגו השלבים, הערכת הזמן והודעות
            תשלום לפי תגיות וסטטוס התשלום ב-Shopify.
          </Text>
        </Banner>
      </BlockStack>
    );
  }

  if (loading) {
    return (
      <BlockStack spacing="tight">
        <Text emphasis="bold">התקדמות ההזמנה</Text>
        <Spinner accessibilityLabel="טוען סטטוס הזמנה" />
      </BlockStack>
    );
  }

  if (error) {
    return (
      <Banner status="critical" title="לא ניתן להציג התקדמות">
        <Text>{error}</Text>
      </Banner>
    );
  }

  const steps = Array.isArray(payload?.steps) ? payload.steps : [];
  const orderTags = Array.isArray(payload?.order_tags) ? payload.order_tags : [];

  return (
    <BlockStack spacing="tight">
      <Text emphasis="bold">התקדמות ההזמנה</Text>
      {payload?.order_name ? (
        <Text size="small">הזמנה {payload.order_name}</Text>
      ) : null}

      {payload?.cancelled_at ? (
        <Banner status="warning" title="ההזמנה בוטלה">
          <Text>ההזמנה סומנה כמבוטלת.</Text>
        </Banner>
      ) : null}

      {payload?.is_payment_blocked && payload?.payment_message_he ? (
        <Banner status="warning" title="תשלום">
          <Text>{payload.payment_message_he}</Text>
        </Banner>
      ) : null}

      {payload?.eta_summary_he ? (
        <Text size="small">{payload.eta_summary_he}</Text>
      ) : null}

      {payload?.fulfillment_message_he ? (
        <Banner status="info" title="מוכן לאיסוף / משלוח">
          <Text>{payload.fulfillment_message_he}</Text>
        </Banner>
      ) : null}

      {steps.length > 0 ? (
        <BlockStack spacing="extraTight">
          {steps.map((step) => (
            <Text key={step.key || step.label_he}>
              {step.done ? "✓ " : "○ "}
              {step.label_he}
              {!step.done && typeof step.eta_days === "number"
                ? ` (הערכה: כ-${step.eta_days} ימים)`
                : ""}
            </Text>
          ))}
        </BlockStack>
      ) : (
        <Text size="small">אין שלבים מוגדרים בהגדרות החנות.</Text>
      )}

      {orderTags.length > 0 ? (
        <BlockStack spacing="extraTight">
          <Text size="small" emphasis="bold">
            תגיות נוספות על ההזמנה
          </Text>
          {orderTags.map((tag) => (
            <Text key={tag} size="small">
              • {tag}
            </Text>
          ))}
        </BlockStack>
      ) : null}
    </BlockStack>
  );
}
