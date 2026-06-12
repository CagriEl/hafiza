#!/usr/bin/env python3
"""Scrape cesit.com and export WordPress WXR import XML."""

from __future__ import annotations

import html
import re
import sys
import time
import unicodedata
from collections import deque
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, parse_qsl, quote, urlencode, urljoin, urlparse, urlunparse
from urllib.request import Request, urlopen

BASE_URL = "https://www.cesit.com/"
OUTPUT_DIR = Path(__file__).resolve().parent.parent / "exports"
USER_AGENT = "Mozilla/5.0 (compatible; CesitWordPressMigrator/1.0)"
REQUEST_DELAY = 0.35

SKIP_PATH_PREFIXES = (
    "/assets/",
    "/fancybox/",
    "/js/",
    "/cdn-cgi/",
    "/en-EN/",
)


class LinkExtractor(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.links: set[str] = set()

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag != "a":
            return
        href = dict(attrs).get("href")
        if href:
            self.links.add(href)


def encode_url(url: str) -> str:
    parsed = urlparse(url)
    path = quote(parsed.path, safe="/:@!$&'()*+,;=-._~")
    query = urlencode(parse_qsl(parsed.query, keep_blank_values=True), doseq=True) if parsed.query else ""
    return urlunparse((parsed.scheme, parsed.netloc, path, parsed.params, query, ""))


def normalize_url(url: str) -> str:
    url = html.unescape(url.strip())
    url = urljoin(BASE_URL, url)
    parsed = urlparse(url)
    if parsed.netloc and "cesit.com" not in parsed.netloc:
        return ""
    path = parsed.path or "/"
    if any(path.startswith(prefix) for prefix in SKIP_PATH_PREFIXES):
        return ""
    if not path.endswith(".cfm") and path not in ("/", "/index.cfm"):
        return ""
    clean = parsed._replace(scheme="https", netloc="www.cesit.com", fragment="")
    normalized = urlunparse(clean)
    if parsed.path in ("", "/") and not parsed.query:
        return "https://www.cesit.com/index.cfm"
    return encode_url(normalized)


def is_news_page(url: str) -> bool:
    query = parse_qs(urlparse(url).query)
    return query.get("menu", [""])[0] == "haberler"


def fetch(url: str) -> str:
    req = Request(url, headers={"User-Agent": USER_AGENT})
    with urlopen(req, timeout=30) as response:
        raw = response.read()
    for encoding in ("utf-8", "iso-8859-9", "windows-1254", "latin-1"):
        try:
            return raw.decode(encoding)
        except UnicodeDecodeError:
            continue
    return raw.decode("utf-8", errors="replace")


def slugify(text: str, fallback: str = "sayfa") -> str:
    text = unicodedata.normalize("NFKD", text)
    text = text.encode("ascii", "ignore").decode("ascii")
    text = text.lower()
    text = re.sub(r"[^a-z0-9]+", "-", text).strip("-")
    return text or fallback


def strip_tags(value: str) -> str:
    value = re.sub(r"(?is)<(script|style).*?>.*?</\1>", " ", value)
    value = re.sub(r"(?s)<.*?>", " ", value)
    value = html.unescape(value)
    return re.sub(r"\s+", " ", value).strip()


def absolutize_urls(fragment: str, page_url: str) -> str:
    def repl_attr(match: re.Match[str]) -> str:
        attr, quote_char, path = match.groups()
        if path.startswith(("http://", "https://", "mailto:", "javascript:", "#", "data:")):
            return match.group(0)
        return f"{attr}={quote_char}{urljoin(page_url, path)}{quote_char}"

    fragment = re.sub(
        r'(src|href)=([\'"])(.*?)\2',
        repl_attr,
        fragment,
        flags=re.IGNORECASE,
    )
    fragment = re.sub(
        r"url\((['\"]?)(.*?)\1\)",
        lambda m: f"url('{urljoin(page_url, m.group(2))}')",
        fragment,
        flags=re.IGNORECASE,
    )
    return fragment


def extract_between(html_text: str, start_marker: str, end_marker: str) -> str:
    start = html_text.find(start_marker)
    if start == -1:
        return ""
    start += len(start_marker)
    end = html_text.find(end_marker, start)
    if end == -1:
        return html_text[start:]
    return html_text[start:end]


def collect_blocks(html_text: str, patterns: list[str]) -> list[str]:
    blocks: list[str] = []
    for pattern in patterns:
        blocks.extend(re.findall(pattern, html_text, re.I | re.S))
    return blocks


def nav_title_for_url(html_text: str, page_url: str) -> str:
    parsed = urlparse(page_url)
    query = parse_qs(parsed.query)
    page_id = query.get("id", [""])[0]
    page_param = query.get("page", [""])[0]
    menu_param = query.get("menu", [""])[0]

    if page_id and page_param:
        match = re.search(
            rf'href="[^"]*id={re.escape(page_id)}[^"]*page=[^"]*"[^>]*title="([^"]+)"',
            html_text,
            re.I,
        )
        if match:
            return strip_tags(match.group(1))
    if page_id and menu_param:
        match = re.search(
            rf'href="[^"]*id={re.escape(page_id)}[^"]*menu={re.escape(menu_param)}[^"]*"[^>]*title="([^"]+)"',
            html_text,
            re.I,
        )
        if match:
            return strip_tags(match.group(1))
    if menu_param == "iletisim":
        return "İletişim"
    if menu_param == "insan-kaynaklari":
        return "İnsan Kaynakları"
    if page_param:
        return page_param.replace("-", " ").replace("/", " / ").upper()
    return ""


def extract_page_content(html_text: str, page_url: str) -> tuple[str, str, str]:
    h1_match = re.search(
        r'<div class="content-header">\s*<h1>(.*?)</h1>',
        html_text,
        re.I | re.S,
    )
    h1_text = strip_tags(h1_match.group(1)) if h1_match else ""
    nav_title = nav_title_for_url(html_text, page_url)
    if nav_title:
        page_title = nav_title
    elif h1_text:
        page_title = h1_text
    else:
        page_title = "Sayfa"
        product_h1 = re.search(
            r'<div class="content-products-right-list-writing">\s*<h1>(.*?)</h1>',
            html_text,
            re.I | re.S,
        )
        if product_h1 and strip_tags(product_h1.group(1)):
            page_title = strip_tags(product_h1.group(1))
        else:
            title_match = re.search(r"<title>(.*?)</title>", html_text, re.I | re.S)
            if title_match:
                page_title = strip_tags(title_match.group(1))
                page_title = page_title.replace(" - Çeşit Mensucat", "").strip()
                if page_title == "Çeşit Mensucat":
                    page_title = "Ana Sayfa"

    query = parse_qs(urlparse(page_url).query)
    menu_param = query.get("menu", [""])[0]
    if menu_param == "iletisim":
        page_title = "İletişim"
    elif menu_param == "insan-kaynaklari" and page_title in ("Ana Sayfa", "Sayfa"):
        page_title = "İnsan Kaynakları"

    content_parts: list[str] = []

    if 'id="home-slogan-area"' in html_text:
        slogan = extract_between(html_text, 'id="home-slogan-area"', 'id="home-news-area"')
        if strip_tags(slogan):
            content_parts.append(f'<section class="home-slogan">{slogan}</section>')

    if 'id="home-news-area"' in html_text:
        news = extract_between(html_text, 'id="home-news-area"', 'id="home-customer-area"')
        if strip_tags(news):
            content_parts.append(f'<section class="home-news">{news}</section>')

    if 'class="contact-map-area"' in html_text or 'id="contact-area"' in html_text:
        contact = extract_between(html_text, 'class="contact-map-area"', 'id="footer"')
        if strip_tags(contact):
            content_parts.append(f'<section class="contact-page">{contact}</section>')

    content_area = extract_between(html_text, 'id="content-area"', 'id="footer"')
    if content_area:
        blocks = collect_blocks(
            content_area,
            [
                r'<div class="content-header">.*?</div>',
                r'<div class="content-writing">.*?</div>\s*</div>',
                r'<div class="content-history-writing">.*?</div>\s*</div>',
                r'<div class="content-history-image">.*?</div>\s*</div>',
                r'<div class="content-history-factoryimage">.*?</div>\s*</div>',
                r'<div class="content-products-right-list-writing">.*?</div>\s*</div>',
                r'<div class="content-products-image-area"[^>]*>.*?</div>',
                r'<div class="content-triangle-picture-area">.*?</div>\s*</div>\s*</div>',
                r'<div class="content-team-writing">.*?</div>\s*</div>',
            ],
        )
        if blocks:
            content_parts.extend(blocks)
        elif strip_tags(content_area):
            content_parts.append(content_area)

    body = absolutize_urls("\n".join(content_parts), page_url)
    body = re.sub(r">\s+<", "><", body).strip()

    meta_desc = ""
    desc_match = re.search(
        r'<meta\s+name="description"\s+content="([^"]*)"',
        html_text,
        re.I,
    )
    if desc_match:
        meta_desc = html.unescape(desc_match.group(1)).strip()

    return page_title, body, meta_desc


def extract_home_news(html_text: str) -> list[dict[str, str]]:
    items: list[dict[str, str]] = []
    for block in re.findall(
        r'id="home-news-item-\d+">(.*?)(?=</div>\s*<div class="home-writing-item-area-inner|</div>\s*</div>\s*<div class="home-news-arrows-area")',
        html_text,
        re.I | re.S,
    ):
        title_match = re.search(r"<h4>(.*?)</h4>", block, re.I | re.S)
        body_match = re.search(r'<div class="home-news-writing">.*?<p>(.*?)</p>', block, re.I | re.S)
        link_match = re.search(r'href="([^"]+)"', block, re.I)
        if not title_match:
            continue
        title = strip_tags(title_match.group(1))
        excerpt_html = body_match.group(1).strip() if body_match else ""
        excerpt_text = strip_tags(excerpt_html)
        source_url = normalize_url(link_match.group(1)) if link_match else ""
        content = f"<p>{excerpt_html or html.escape(excerpt_text)}</p>"
        items.append(
            {
                "title": title,
                "content": absolutize_urls(content, BASE_URL),
                "excerpt": excerpt_text,
                "source_url": source_url,
            }
        )
    return items


def discover_links(html_text: str) -> set[str]:
    parser = LinkExtractor()
    parser.feed(html_text)
    links: set[str] = set()
    for href in parser.links:
        normalized = normalize_url(href)
        if normalized and not is_news_page(normalized):
            links.add(normalized)
    return links


def crawl_site(start_url: str) -> dict[str, str]:
    queue: deque[str] = deque([start_url])
    seen: set[str] = set()
    pages: dict[str, str] = {}

    while queue:
        url = queue.popleft()
        if url in seen:
            continue
        seen.add(url)
        try:
            html_text = fetch(url)
        except (HTTPError, URLError, TimeoutError) as exc:
            print(f"[skip] {url}: {exc}", file=sys.stderr)
            continue
        pages[url] = html_text
        print(f"[ok] {len(pages):>3} {url}")
        for link in sorted(discover_links(html_text)):
            if link not in seen:
                queue.append(link)
        time.sleep(REQUEST_DELAY)

    return pages


def xml_escape(value: str) -> str:
    return (
        value.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def cdata(value: str) -> str:
    return f"<![CDATA[{value.replace(']]>', ']]]]><![CDATA[>')}]]>"


def build_wxr(pages: dict[str, str], news_items: list[dict[str, str]]) -> str:
    now = datetime.now(timezone.utc)
    now_local = datetime.now()
    post_id = 1
    used_slugs: set[str] = set()
    items_xml: list[str] = []

    def next_slug(base: str) -> str:
        slug = slugify(base)
        if slug not in used_slugs:
            used_slugs.add(slug)
            return slug
        counter = 2
        while f"{slug}-{counter}" in used_slugs:
            counter += 1
        final = f"{slug}-{counter}"
        used_slugs.add(final)
        return final

    def add_item(
        *,
        title: str,
        content: str,
        slug: str,
        post_type: str,
        source_url: str,
        excerpt: str = "",
    ) -> None:
        nonlocal post_id
        date_str = now_local.strftime("%Y-%m-%d %H:%M:%S")
        gmt_str = now.strftime("%Y-%m-%d %H:%M:%S")
        pub_date = now.strftime("%a, %d %b %Y %H:%M:%S +0000")
        guid = source_url or f"{BASE_URL}?p={post_id}"
        items_xml.append(
            "\n".join(
                [
                    "  <item>",
                    f"    <title>{xml_escape(title)}</title>",
                    f"    <link>{xml_escape(source_url)}</link>",
                    f"    <pubDate>{pub_date}</pubDate>",
                    "    <dc:creator><![CDATA[admin]]></dc:creator>",
                    f'    <guid isPermaLink="false">{xml_escape(guid)}</guid>',
                    "    <description></description>",
                    f"    <content:encoded>{cdata(content)}</content:encoded>",
                    f"    <excerpt:encoded>{cdata(excerpt)}</excerpt:encoded>",
                    f"    <wp:post_id>{post_id}</wp:post_id>",
                    f"    <wp:post_date>{date_str}</wp:post_date>",
                    f"    <wp:post_date_gmt>{gmt_str}</wp:post_date_gmt>",
                    f"    <wp:post_modified>{date_str}</wp:post_modified>",
                    f"    <wp:post_modified_gmt>{gmt_str}</wp:post_modified_gmt>",
                    "    <wp:comment_status>closed</wp:comment_status>",
                    "    <wp:ping_status>closed</wp:ping_status>",
                    f"    <wp:post_name>{xml_escape(slug)}</wp:post_name>",
                    "    <wp:status>publish</wp:status>",
                    "    <wp:post_parent>0</wp:post_parent>",
                    "    <wp:menu_order>0</wp:menu_order>",
                    f"    <wp:post_type>{post_type}</wp:post_type>",
                    "    <wp:post_password></wp:post_password>",
                    "    <wp:is_sticky>0</wp:is_sticky>",
                    "  </item>",
                ]
            )
        )
        post_id += 1

    for url in sorted(pages):
        title, content, excerpt = extract_page_content(pages[url], url)
        if not content:
            print(f"[warn] empty content: {url}", file=sys.stderr)
        query = parse_qs(urlparse(url).query)
        page_param = query.get("page", [""])[0]
        menu_param = query.get("menu", [""])[0]
        if page_param:
            slug_base = page_param.split("/")[0]
        elif menu_param:
            slug_base = menu_param
        else:
            slug_base = title
        add_item(
            title=title,
            content=content,
            slug=next_slug(slug_base),
            post_type="page",
            source_url=url,
            excerpt=excerpt,
        )

    for news in news_items:
        add_item(
            title=news["title"],
            content=news["content"],
            slug=next_slug(news["title"]),
            post_type="post",
            source_url=news.get("source_url", ""),
            excerpt=news.get("excerpt", ""),
        )

    return "\n".join(
        [
            '<?xml version="1.0" encoding="UTF-8" ?>',
            '<rss version="2.0"',
            '  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"',
            '  xmlns:content="http://purl.org/rss/1.0/modules/content/"',
            '  xmlns:wfw="http://wellformedweb.org/CommentAPI/"',
            '  xmlns:dc="http://purl.org/dc/elements/1.1/"',
            '  xmlns:wp="http://wordpress.org/export/1.2/">',
            "<channel>",
            "  <title>Çeşit Mensucat</title>",
            f"  <link>{BASE_URL}</link>",
            "  <description>cesit.com icerik aktarimi</description>",
            f"  <pubDate>{now.strftime('%a, %d %b %Y %H:%M:%S +0000')}</pubDate>",
            "  <language>tr</language>",
            "  <wp:wxr_version>1.2</wp:wxr_version>",
            f"  <wp:base_site_url>{BASE_URL}</wp:base_site_url>",
            f"  <wp:base_blog_url>{BASE_URL}</wp:base_blog_url>",
            "  <generator>https://wordpress.org/?v=6.7</generator>",
            "  <wp:author>",
            "    <wp:author_id>1</wp:author_id>",
            "    <wp:author_login><![CDATA[admin]]></wp:author_login>",
            "    <wp:author_email><![CDATA[admin@example.com]]></wp:author_email>",
            "    <wp:author_display_name><![CDATA[admin]]></wp:author_display_name>",
            "    <wp:author_first_name><![CDATA[]]></wp:author_first_name>",
            "    <wp:author_last_name><![CDATA[]]></wp:author_last_name>",
            "  </wp:author>",
            *items_xml,
            "</channel>",
            "</rss>",
            "",
        ]
    )


def main() -> None:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    print("Site taranıyor...")
    pages = crawl_site("https://www.cesit.com/index.cfm")

    home_html = pages.get("https://www.cesit.com/index.cfm", "")
    news_items = extract_home_news(home_html) if home_html else []

    wxr = build_wxr(pages, news_items)
    output_file = OUTPUT_DIR / "cesit-wordpress-import.xml"
    output_file.write_text(wxr, encoding="utf-8")

    summary_file = OUTPUT_DIR / "cesit-url-list.txt"
    summary_lines = [
        f"Toplam sayfa: {len(pages)}",
        f"Toplam haber: {len(news_items)}",
        "",
        "SAYFALAR:",
        *sorted(pages),
        "",
        "HABERLER:",
    ]
    for news in news_items:
        summary_lines.append(f"- {news['title']} -> {news.get('source_url', '')}")
    summary_file.write_text("\n".join(summary_lines) + "\n", encoding="utf-8")

    print(f"\nTamamlandi.")
    print(f"XML: {output_file}")
    print(f"URL listesi: {summary_file}")
    print(f"Sayfa: {len(pages)}, Haber: {len(news_items)}")


if __name__ == "__main__":
    main()
