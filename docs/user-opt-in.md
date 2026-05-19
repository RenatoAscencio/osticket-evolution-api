# Customer opt-in for WhatsApp notifications

This plugin can read a custom field on each customer's osTicket profile to
decide whether to send them WhatsApp notifications. Customers see the field
in their own profile editor on the client portal and can toggle it on/off
at will.

This document walks an admin through enabling the feature.

---

## What it looks like to the customer

Once the field is added, when a customer clicks **My Profile** on the public
osTicket portal, they see a checkbox like:

> ☐ Receive WhatsApp notifications about my tickets

If they uncheck it and save, the plugin skips all WhatsApp messages to that
customer — for every event — until they re-check it. Admin notifications
are unaffected.

---

## Setup — one-time, in the osTicket admin UI

### 1. Open the Contact Information form

**Admin Panel → Manage → Forms → Contact Information**

This is the built-in form osTicket attaches to every user profile.

### 2. Add a new field

Click **Add new field**. Fill in:

| Field property | Value |
| -------------- | ----- |
| **Label** | `Receive WhatsApp notifications about my tickets` (or whatever wording fits your tone) |
| **Variable** | `whatsapp_opt_in` *(must match the plugin's `opt_in_field_variable` setting — the default is `whatsapp_opt_in`)* |
| **Type** | `Checkbox` |
| **Visible to customer** | ✅ Yes |
| **Editable by customer** | ✅ Yes |
| **Default value** | Up to you. Check it if you want existing customers to be opted-in by default. |

Save the form.

### 3. Tell the plugin to respect it

In the plugin config (**Manage → Plugins → Evolution API Notifications →
Configure**), confirm:

| Setting | Value |
| ------- | ----- |
| **Respect customer opt-in preference** | ✅ ON |
| **Opt-in field variable name** | `whatsapp_opt_in` (match the variable name from step 2) |
| **Default to opt-IN when field is absent** | Choose: ON if you want existing customers who haven't seen the new field yet to keep receiving notifications. OFF if you want strict opt-in (privacy-first). |

### 4. Verify

1. Open the customer portal (`https://your-osticket/`) and log in as a test
   user.
2. Click **Profile** in the user dropdown.
3. The new checkbox should be visible. Toggle it off and save.
4. As staff, reply to one of that user's tickets.
5. Watch the plugin log — you should see:
   ```
   [INFO] Customer opted out of WhatsApp notifications — skipping ticket #N
   ```
6. Toggle the checkbox back on, repeat. The next staff reply should reach
   WhatsApp normally.

---

## Behavior matrix

| Customer's field value | Plugin behavior |
| ---------------------- | --------------- |
| Field exists, checked (`1`/`true`) | Send normally. |
| Field exists, unchecked (`0`/`false`) | **Skip every send** to this customer. Logged at `info` level. |
| Field does not exist (e.g. legacy customer, admin hasn't added the field) | Use the *"Default to opt-IN when field is absent"* setting from the plugin config. |
| Plugin's `Respect customer opt-in preference` is OFF | Field is ignored; all sends proceed (subject to per-event toggles). |

---

## Programmatic notes

The plugin's `userOptedIn()` looks up the value by trying these APIs in
order, so it works across multiple osTicket versions:

1. `User::getForms()` → iterates `DynamicFormEntry::getField($variable)`
2. `User::getDynamicData()` → same
3. `FormEntry::getAnswers()` → matches by `field->get('name')`

If you've forked osTicket and the field lives somewhere else, you can adapt
`readUserCustomField()` in `plugin/evolution.php`.

---

## Privacy considerations

- The opt-in is per-customer and lives on the customer's own profile, which
  they control. Staff can see the value but cannot change it on the
  customer's behalf unless they have edit access to the user account.
- The plugin **only reads** the field; it never writes to it.
- Toggling the field off does **not** delete past notifications — only
  future ones are skipped.
- Admin notifications are completely unaffected — staff still get pinged
  about new tickets and replies even if the customer is opted out.
- The cache of WhatsApp-existence checks (`<prefix>evolution_wa_cache`)
  is **not** consulted when the customer is opted out; the plugin returns
  early before any Evolution API call is made, so the customer's phone is
  never sent to Evolution either.
