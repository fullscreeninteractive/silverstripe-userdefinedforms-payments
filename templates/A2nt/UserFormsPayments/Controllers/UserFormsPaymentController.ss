<!doctype html>
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
    <% if $Content %>
        <div class="userforms-payment__content">
            <h1 class="userforms-payment__title">$Title</h1>
            $Content
        </div>
    <% end_if %>

    $Form

    <% if $BackButton %>
        <a href="$BackButtonLink" class="userforms-payment__back-button">$BackButton</a>
    <% end_if %>
</div>
</body>
</html>
