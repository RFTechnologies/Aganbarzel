import {
  Banner,
  Badge,
  BlockStack,
  InlineStack,
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

function getPrimaryStatus(payload) {
  if (payload?.cancelled_at) {
    return {
      bannerStatus: "critical",
      title: "ההזמנה בוטלה",
      message: "ההזמנה סומנה כמבוטלת במערכת.",
      badgeTone: "critical",
      badgeLabel: "בוטל",
    };
  }

  if (payload?.is_payment_blocked && payload?.payment_message_he) {
    return {
      bannerStatus: "warning",
      title: "נדרש להסדיר תשלום",
      message: payload.payment_message_he,
      badgeTone: "warning",
      badgeLabel: "ממתין לתשלום",
    };
  }

  if (payload?.fulfillment_message_he) {
    return {
      bannerStatus: "success",
      title: "המוצר מוכן לאיסוף / משלוח",
      message: payload.fulfillment_message_he,
      badgeTone: "success",
      badgeLabel: "מוכן",
    };
  }

  return {
    bannerStatus: "info",
    title: "ההזמנה בתהליך ייצור",
    message: "המערכת מעדכנת את ההתקדמות לפי תגיות ההזמנה ב-Shopify.",
    badgeTone: "info",
    badgeLabel: "בתהליך",
  };
}

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
  const doneSteps = steps.filter((step) => step.done).length;
  const totalSteps = steps.length;
  const nextPendingStep = steps.find((step) => !step.done);
  const status = getPrimaryStatus(payload);

  return (
    <BlockStack spacing="loose">
      <InlineStack spacing="tight" inlineAlignment="space-between">
        <Text emphasis="bold">התקדמות ההזמנה</Text>
        <Badge tone={status.badgeTone}>{status.badgeLabel}</Badge>
      </InlineStack>

      {payload?.order_name ? (
        <Text size="small">מספר הזמנה: {payload.order_name}</Text>
      ) : null}

      <Banner status={status.bannerStatus} title={status.title}>
        <Text>{status.message}</Text>
      </Banner>

      {totalSteps > 0 ? (
        <BlockStack spacing="extraTight">
          <Text emphasis="bold" size="small">
            סטטוס שלבים: {doneSteps}/{totalSteps} הושלמו
          </Text>
          {nextPendingStep ? (
            <Text size="small">השלב הבא: {nextPendingStep.label_he}</Text>
          ) : (
            <Text size="small">כל השלבים הושלמו.</Text>
          )}
        </BlockStack>
      ) : (
        <Banner status="warning" title="אין שלבים מוגדרים">
          <Text>יש להגדיר שלבים בשרת לפני הצגת רשימת ההתקדמות.</Text>
        </Banner>
      )}

      {payload?.eta_summary_he ? (
        <Banner status="info" title="הערכת זמן">
          <Text>{payload.eta_summary_he}</Text>
        </Banner>
      ) : null}

      {steps.length > 0 ? (
        <BlockStack spacing="tight">
          <Text emphasis="bold">צ'קליסט ייצור</Text>
          {steps.map((step) => (
            <BlockStack key={step.key || step.label_he} spacing="none">
              <InlineStack spacing="tight" inlineAlignment="space-between">
                <Text emphasis={step.done ? "bold" : undefined}>
                  {step.done ? "✓ הושלם: " : "○ ממתין: "}
                  {step.label_he}
                </Text>
                <Badge tone={step.done ? "success" : "attention"}>
                  {step.done ? "בוצע" : "בהמתנה"}
                </Badge>
              </InlineStack>
              {!step.done && typeof step.eta_days === "number" ? (
                <Text size="small">הערכת זמן לשלב זה: כ-{step.eta_days} ימים</Text>
              ) : null}
            </BlockStack>
          ))}
        </BlockStack>
      ) : null}

      {orderTags.length > 0 ? (
        <BlockStack spacing="tight">
          <Text size="small" emphasis="bold">
            תגיות טכניות על ההזמנה ({orderTags.length})
          </Text>
          <Text size="small">
            תגיות אלו משמשות את המערכת לחישוב ההתקדמות והסטטוס.
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
