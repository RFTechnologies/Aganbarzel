import {
  Banner,
  Badge,
  BlockStack,
  Card,
  Divider,
  Grid,
  InlineStack,
  Spinner,
  Text,
  View,
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

function getPrimaryStatus(payload, tagsNormalized) {
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

  if (tagsNormalized.includes("fulfillment-delivery")) {
    return {
      bannerStatus: "success",
      title: "המוצר מוכן למשלוח",
      message:
        payload?.fulfillment_message_he ||
        "ההזמנה מוכנה למשלוח. תקבלו עדכון כשהמשלוח יישלח.",
      badgeTone: "success",
      badgeLabel: "מוכן",
    };
  }

  if (tagsNormalized.includes("fulfillment-pickup")) {
    return {
      bannerStatus: "success",
      title: "המוצר מוכן לאיסוף",
      message:
        payload?.fulfillment_message_he ||
        "ההזמנה מוכנה לאיסוף עצמי. תקבלו עדכון עם פרטי האיסוף.",
      badgeTone: "success",
      badgeLabel: "מוכן",
    };
  }

  return {
    bannerStatus: "info",
    title: "ההזמנה בתהליך ייצור",
    message: null,
    badgeTone: "info",
    badgeLabel: "בתהליך",
  };
}

function stepLabel(step) {
  if (!step || typeof step !== "object") {
    return "";
  }
  const h = step.label_he;
  const l = step.label;
  if (typeof h === "string" && h.trim() !== "") {
    return h;
  }
  if (typeof l === "string" && l.trim() !== "") {
    return l;
  }
  return "";
}

/**
 * done | in_progress | pending. Uses API `step_state` when present; else first
 * not-done step is in_progress (match mockup / backend `applyFirstPendingAsInProgress`).
 */
function stepStateFor(step, index, firstOpenIndex) {
  if (step && typeof step === "object") {
    const s = step.step_state;
    if (s === "done" || s === "in_progress" || s === "pending") {
      return s;
    }
    if (step.done) {
      return "done";
    }
    if (index === firstOpenIndex) {
      return "in_progress";
    }
  }
  return "pending";
}

function formatCompletedAt(iso) {
  if (!iso || typeof iso !== "string") {
    return "";
  }
  try {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) {
      return "";
    }
    return new Intl.DateTimeFormat("he-IL", {
      dateStyle: "short",
      timeStyle: "short",
    }).format(d);
  } catch {
    return "";
  }
}

function estimateColumnText(step) {
  if (
    typeof step?.estimate_display === "string" &&
    step.estimate_display.trim() !== ""
  ) {
    return step.estimate_display;
  }
  if (
    typeof step?.notes_display === "string" &&
    step.notes_display.trim() !== ""
  ) {
    return step.notes_display;
  }
  if (typeof step?.note === "string" && step.note.trim() !== "") {
    return step.note;
  }
  return "";
}

function StatusCell({state}) {
  if (state === "done") {
    return (
      <InlineStack spacing="tight" blockAlignment="center" inlineAlignment="start">
        <Text size="small" appearance="success" emphasis="bold">
          ✓
        </Text>
        <Text size="small" appearance="success" emphasis="bold">
          Completed
        </Text>
      </InlineStack>
    );
  }
  if (state === "in_progress") {
    return (
      <InlineStack spacing="tight" blockAlignment="center" inlineAlignment="start">
        <Text size="small" appearance="info">
          ●
        </Text>
        <Text size="small" appearance="info" emphasis="bold">
          In Progress
        </Text>
      </InlineStack>
    );
  }
  return (
    <InlineStack spacing="tight" blockAlignment="center" inlineAlignment="start">
      <Text size="small" appearance="subdued">
        ◯
      </Text>
      <Text size="small" appearance="subdued" emphasis="bold">
        Pending
      </Text>
    </InlineStack>
  );
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
            בלקוחות אמיתיים מוצגת רשימת שלבים לפי הגדרה במטא-שדה של המוצר
            (Production checklist), תאריכי השלמה לפי תגיות (ועדכון אוטומטי לשלב
            הראשון), והערות עיכוב מהמטא-שדה Production update בהזמנה.
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
  const tagsNormalized = orderTags
    .filter((tag) => typeof tag === "string" && tag.trim() !== "")
    .map((tag) => String(tag).trim().toLowerCase());
  const status = getPrimaryStatus(payload, tagsNormalized);
  const steps = Array.isArray(payload?.steps) ? payload.steps : [];
  const firstOpenIndex = steps.findIndex((s) => s && !s.done);
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
        {status.message ? <Text>{status.message}</Text> : null}
      </Banner>

      {typeof payload?.production_update_note === "string" &&
      payload.production_update_note.trim() !== "" ? (
        <Banner status="warning" title="עדכון מהיצור">
          <Text>{payload.production_update_note.trim()}</Text>
        </Banner>
      ) : null}

      {steps.length === 0 ? (
        <Banner status="warning" title="אין רשימת שלבים זמינה">
          <Text>
            אין לנו עדיין מידע מפורט על שלבי הייצור לפריטים בהזמנה זו. אפשר
            לפנות לחנות לעדכון.
          </Text>
        </Banner>
      ) : null}

      {steps.length > 0 ? (
        <Card padding>
          <BlockStack spacing="none">
            <Text size="small" appearance="subdued" emphasis="bold">
              Order production checklist (visual component)
            </Text>
            <Divider />
            <Grid
              columns={["1fr", "auto", "0.95fr", "1.15fr"]}
              spacing="none"
              blockAlignment="center"
            >
              <Text size="small" appearance="subdued" emphasis="bold">
                Production stage
              </Text>
              <Text size="small" appearance="subdued" emphasis="bold">
                Status
              </Text>
              <Text size="small" appearance="subdued" emphasis="bold">
                Completed
              </Text>
              <Text size="small" appearance="subdued" emphasis="bold">
                Notes / estimates
              </Text>
            </Grid>
            <Divider />
            {steps.map((step, index) => {
              const label = stepLabel(step);
              const key = String(step.key || "step") + "-" + String(index);
              const state = stepStateFor(step, index, firstOpenIndex);
              const notesText = estimateColumnText(step);
              const completedLabel = formatCompletedAt(step.completed_at);
              const isLast = index === steps.length - 1;
              return (
                <BlockStack key={key} spacing="none">
                  <Grid
                    columns={["1fr", "auto", "0.95fr", "1.15fr"]}
                    spacing="none"
                    blockAlignment="center"
                  >
                    <Text size="small" emphasis="bold">
                      {label}
                    </Text>
                    <View minInlineSize={120}>
                      <StatusCell state={state} />
                    </View>
                    <Text size="small" appearance="subdued">
                      {completedLabel !== "" ? completedLabel : "—"}
                    </Text>
                    <Text size="small" appearance="subdued">
                      {notesText}
                    </Text>
                  </Grid>
                  {!isLast ? <Divider /> : null}
                </BlockStack>
              );
            })}
          </BlockStack>
        </Card>
      ) : null}

      {payload?.eta_summary_he && steps.length > 0 ? (
        <Text size="small" appearance="subdued">
          {payload.eta_summary_he}
        </Text>
      ) : null}

      {orderTags.length > 0 ? (
        <BlockStack spacing="tight">
          <Text size="small" appearance="subdued" emphasis="bold">
            תגיות בהזמנה
          </Text>
          <Text size="small" appearance="subdued">
            {orderTags.join(", ")}
          </Text>
        </BlockStack>
      ) : null}
    </BlockStack>
  );
}
