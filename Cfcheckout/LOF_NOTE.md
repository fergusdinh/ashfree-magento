First customization for module to add trigger events to use for other extension:

1. Payment success - call when payment response with status SUCCESS

trigger event name:

```cfcheckout_controller_standard_response_success```

with params:

```
'order_ids' => [$order->getId()],
'order' => $order,
'status' => $status,
'request' => $this
```

2. Other payment status: CANCELLED

trigger event name:

```cfcheckout_controller_standard_cancelled```

with params:

```
'order_ids' => [$order->getId()],
'order' => $order,
'quote' => $quote,
'status' => $status,
'request' => $this
```

3. Other payment status: FAILED

trigger event name:

```cfcheckout_controller_standard_failed```

with params:

```
'order_ids' => [$order->getId()],
'order' => $order,
'quote' => $quote,
'status' => $status,
'request' => $this
```

4. Other payment status: PENDING

trigger event name:

```cfcheckout_controller_standard_pending```

with params:

```
'order_ids' => [$order->getId()],
'order' => $order,
'quote' => $quote,
'status' => $status,
'request' => $this
```

5. Other payment status: ORTHER

trigger event name:

```cfcheckout_controller_standard_response```

with params:

```
'order_ids' => [$order->getId()],
'order' => $order,
'quote' => $quote,
'status' => $status,
'request' => $this
```

6. Cancelled Payment Redirect - call after cancel payement when redirect

trigger event name:

```cfcheckout_controller_standard_redirect```

with params:

```
'order' => $order,
'request' => $this
```
