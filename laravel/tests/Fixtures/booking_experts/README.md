# BookingExperts admin HTML fixtures

Captured from `app.bookingexperts.com` on **2026-04-30** with a real
production session, used by `tests/Feature/BookingExpertsBrowserTest.php`
to exercise the HTML parser without round-tripping the live backend.

| File                                         | Captured URL                                                                                | Notes |
| -------------------------------------------- | ------------------------------------------------------------------------------------------- | --- |
| `parks.html`                                 | `GET /parks`                                                                                | Org-switcher; 2 dev-orgs (1046, 1052). |
| `app_list_1046_page1.html`                   | `GET /organizations/1046/apps/developer/applications`                                       | 3 apps owned by Verbleif (90, 132, 594). |
| `app_list_1052_unauthorized.html`            | `GET /organizations/1052/apps/developer/applications` → 401                                 | Member-without-dev-permission case; controller must skip silently. |
| `app_detail_1046_90_page1.html`              | `GET /organizations/1046/apps/developer/applications/90`                                    | The "Bekijk alle Installaties" template embeds the **full** subscriber list inline (36 rows). |
| `paginated_subs_page1.html`                  | _Synthesised._ Tiny HTML emulating a paginated response with a `?page=2` next-link.         | Used to exercise the pagination loop. |
| `paginated_subs_page2.html`                  | _Synthesised._ Same shape as page 1 but with no further pages.                              | |

## Re-capturing

Real BookingExperts HTML can drift; if a selector starts logging
`bex.browse: parser found 0 …`, re-capture with:

```bash
# Pull cookies for an active production session
php artisan tinker --execute='$s = \App\Models\BexSession::where("environment","production")->whereNull("expired_at")->latest()->first(); echo collect($s->cookies)->map(fn($c) => $c["name"]."=".$c["value"])->implode("; ");' > /tmp/bex-cookies.txt

# Save the page
curl -sSL -b "$(cat /tmp/bex-cookies.txt)" \
  -H "User-Agent: Mozilla/5.0" \
  https://app.bookingexperts.com/<path> \
  > tests/Fixtures/booking_experts/<file>.html
```

Update the table above with what URL each new fixture came from.
