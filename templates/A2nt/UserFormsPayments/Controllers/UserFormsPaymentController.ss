<doctype html>
<html>
<head>
    <% base_tag %>
    $MetaTags(false)
    <title>$Title.XML -<% if $Parent %> $Parent.Title -<% end_if %> $SiteConfig.Title</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <% require css("a2nt/userdefinedforms-payments:css/payments.css") %>
</head>
<body>
<div class="userforms-payment userforms-payment--pay">
    $Form
</div>
</body>
</html>
