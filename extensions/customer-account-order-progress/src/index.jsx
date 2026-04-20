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

function formatTagLabel(tag) {
  return String(tag)
    .replace(/[_-]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function getTagTone(tag) {
  const value = String(tag).toLowerCase();
  if (value.includes("delivery") || value.includes("pickup") || value.includes("ready")) {
    return "success";
  }
  if (value.includes("pending") || value.includes("hold") || value.includes("blocked")) {
    return "warning";
  }
  return "info";
}

function getTagTextAppearance(tag) {
  const tone = getTagTone(tag);
  if (tone === "success") {
    return {appearance: "success", check: "✅"};
  }
  if (tone === "warning") {
    return {appearance: "warning", check: "⚠"};
  }
  return {appearance: "info", check: "✔"};
}

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
            ברגע השמירה, בדף ההזמנה האמיתי יוצגו תגיות ההזמנה כצ'קליסט דינמי
            לפי מה שנוסף להזמנה ב-Shopify Admin.
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

  const orderTags = Array.isArray(payload?.order_tags) ? payload.order_tags : [];
  const checklistTags = orderTags.filter((tag) => typeof tag === "string" && tag.trim() !== "");
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

      {checklistTags.length > 0 ? (
        <BlockStack spacing="extraTight">
          <Text emphasis="bold" size="small">
            צ'קליסט דינמי לפי תגיות: {checklistTags.length} תגיות פעילות
          </Text>
          <Text size="small">
            כל תג שמתווסף להזמנה ב-Shopify Admin יוצג כאן אוטומטית.
          </Text>
        </BlockStack>
      ) : (
        <Banner status="warning" title="אין תגיות על ההזמנה">
          <Text>
            לא נמצאו תגיות להזמנה זו. הוספת תגיות ב-Shopify Admin תציג אותן כאן
            מייד כצ'קליסט.
          </Text>
        </Banner>
      )}

      {checklistTags.length > 0 ? (
        <BlockStack spacing="tight">
          <Text emphasis="bold">צ'קליסט תגיות ההזמנה</Text>
          {checklistTags.map((tag) => (
            <BlockStack key={tag} spacing="extraTight">
              <InlineStack spacing="extraTight" inlineAlignment="space-between">
                <InlineStack spacing="extraTight">
                  <Text emphasis="bold" appearance={getTagTextAppearance(tag).appearance}>
                    {getTagTextAppearance(tag).check} {formatTagLabel(tag)}
                  </Text>
                </InlineStack>
                <Badge tone="success">עודכן</Badge>
              </InlineStack>
            </BlockStack>
          ))}
        </BlockStack>
      ) : null}
    </BlockStack>
  );
}
