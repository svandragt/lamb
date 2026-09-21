---
title: Listings
parent: Content
---

A listing is a post that says something is for sale. It is an ordinary Lamb post with `post-type: listing` in its front-matter, so it is written, edited, tagged, scheduled and deleted exactly like any other post — it just also carries a price and renders as a product rather than as an article.

There is no checkout, no payment handling and no approval queue. It is your site, so it is your rules: a buyer contacts you the way the listing says, and you settle payment and delivery between yourselves.

## Writing a listing

```markdown
---
title: Blue Wool Jumper
post-type: listing
price: '25.00'
currency: EUR
condition: used
contact: https://example.com/contact
---

Warm, barely worn, no holes. Collection from Brighton or postage at cost. #knitwear
```

Every field except `post-type` is optional. A listing with no price is published as a product without an offer, which is the right shape for "free to a good home" or "make me an offer". A price needs a `currency` beside it to become an offer.

| Field | Meaning |
|-------|---------|
| `post-type` | `listing`. Anything else is an ordinary post. |
| `price` | A number, e.g. `25` or `25.00`. Quote it to keep trailing zeros. |
| `currency` | A three-letter ISO code, e.g. `EUR`, `GBP`, `USD`. |
| `condition` | One of `new`, `used`, `refurbished`, `damaged`. |
| `contact` | How a buyer reaches you. Prefer a URL — see below. Free text otherwise: a handle, a phone number, an address. |

`post_type` works as well as `post-type`, as it does for every front-matter key.

A malformed value is held back from the structured data rather than published as nonsense: `price: best offer` is not a number, `currency: euros` is not a code, and `condition: mint` is not one of the four.

You don't have to spot that yourself. Open the listing while logged in and Lamb shows you — and only you — what its data actually says:

> Only you can see this. This listing's data:
> - currency: "euros" was not understood, so it is not in the listing data. Expected a three-letter code, like EUR, GBP or USD.
> - price is set but currency is not, so no offer is published. Add a currency, like EUR, GBP or USD.

A listing with nothing to report shows nothing at all, and visitors never see the note. Because it checks the finished product rather than each line on its own, it also catches the mistake no single field can: a price and a currency are each optional, but an amount with no currency is an offer nobody can act on, so Lamb publishes the item without an offer rather than claiming 25.00 of unspecified money.

Save a listing as a `draft: true` first and its [preview]({{ site.baseurl }}{% link drafts.md %}) link shows the same report, so you can get the data right before anything is published.

### Contact without publishing an address

Prefer a URL in `contact` — a link to your contact page, a profile, or a form:

```yaml
contact: https://example.com/contact
```

A value that is a well-formed `http(s)` URL renders as a link; anything else (a handle, a phone number, an address) stays as plain text. Other schemes are never linked.

This matters more than it does on an ordinary page, because the contact value travels: it is in the listing page, in both feeds, and in the JSON Feed's `_listing` object, so it reaches every subscriber and aggregator, not just people who visit. An address written here is published widely and permanently.

There is no web standard that means "contact me here" — HTML's `rel="contact"` was removed for colliding with [XFN](https://microformats.org/wiki/rel), where it describes a relationship instead. The [IndieWeb convention](https://indieweb.org/contact) is to keep an `h-card` on a contact page listing the ways you want to be reached, in your order of preference, and to link to that. Obfuscating an address is not a substitute: the [IndieWeb wiki](https://indieweb.org/spam) notes that robots still index the tricks, and that information given to an arbitrary site is available to attackers too.

Tags work as normal, and are what categorise a listing. Write `#knitwear` in the body rather than reaching for a separate category field — there isn't one, on purpose.

## What a listing page publishes

A listing page carries a [schema.org](https://schema.org/Product) `Product` with an `Offer`, so a search engine or a listings aggregator reads the price, currency and condition as commerce rather than guessing at your prose. The same terms are marked up in the page itself with microformats2 (`h-product`, `p-price`, `p-condition`), so an IndieWeb reader sees them too.

The offer is always marked as in stock. Lamb has no sold or reserved state yet, so a listing is on offer for exactly as long as the post exists: when an item sells, delete the post (or make it a draft) and it leaves the listings page and its feed on the next request.

## The listings page and feed

Listings appear in your ordinary site feed alongside everything else, and also in a feed of their own:

- `/listings` — the listings, newest first
- `/listings/feed` — Atom, the twenty most recently updated listings
- `/listings/feed.json` — the same as JSON Feed

The dedicated feed is the point of the whole exercise: someone building a directory of sellers can subscribe to the listings alone, without having to filter your holiday photos out of your main feed. Drafts, trashed posts and posts scheduled for the future stay out of it, exactly as they do everywhere else.

Each entry carries the listing's terms, not just its prose: the price, condition and contact are appended to the item content as the same `p-price`/`p-condition` microformats markup the page uses, and the JSON Feed additionally carries them as a `_listing` extension object — so an aggregator reads them straight from the feed instead of fetching every permalink.

If you have a [WebSub]({{ site.baseurl }}{% link feeds.md %}) hub configured, publishing a listing notifies it about the listings feed as well as the main one, so a directory subscribed to your listings is pushed the new one instead of waiting to poll.

## Subscribing to someone else's listings

Lamb's [feed reader]({{ site.baseurl }}{% link cross-posting.md %}) can subscribe to another seller's `/listings/feed`, but what it stores is a short quoted excerpt with a link back to the original — a citation, not a copy. An ingested listing is deliberately **not** marked as a listing of yours: it stays out of your `/listings` page and its feed, and publishes no product markup.

That is on purpose. Your site would otherwise advertise a schema.org `Offer` for goods you do not have, naming you as the seller, on the strength of someone else's feed. A directory that aggregates other people's listings is a different job from the personal feed reader.

## Publishing a listing over Micropub

A [Micropub]({{ site.baseurl }}{% link micropub.md %}) client that creates an `h-product` gets a listing. The listing fields are ordinary Micropub properties:

```
POST /micropub
Authorization: Bearer <token>

h=product
&name=Blue+Wool+Jumper
&content=Warm,+barely+worn.
&price=25.00
&currency=EUR
&condition=used
&contact=sander@example.com
&category=knitwear
```

Everything else works as it does for a note: photos, `mp-syndicate-to`, `post-status=draft` and a future `published` date all behave the same way. A `q=source` query reports a listing back as `h-product` with its fields, so a client can edit what it created. Creates that declare no type, or `h-entry`, are unaffected.

## Related

* [Post Types]({{ site.baseurl }}{% link post-types.md %}): Statuses and pages, and how front-matter drives them.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Publishing to Lamb from a Micropub client.
* [Feeds]({{ site.baseurl }}{% link feeds.md %}): The site's Atom and JSON feeds.
* [Sharing and Discovery]({{ site.baseurl }}{% link sharing-discovery.md %}): How Lamb announces posts to the wider web.
* [Search Engines]({{ site.baseurl }}{% link search-engines.md %}): What Lamb tells crawlers about your posts.
