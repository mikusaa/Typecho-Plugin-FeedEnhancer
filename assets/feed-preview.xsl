<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet
    version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:media="http://search.yahoo.com/mrss/"
    exclude-result-prefixes="dc media"
>
    <xsl:output method="html" encoding="UTF-8" omit-xml-declaration="yes"/>
    <xsl:strip-space elements="*"/>

    <xsl:template match="/">
        <html lang="en">
            <head>
                <meta charset="UTF-8"/>
                <meta name="viewport" content="width=device-width, initial-scale=1"/>
                <title><xsl:value-of select="rss/channel/title"/></title>
                <style>
                    :root {
                        color-scheme: light dark;
                        --page: #f6f8f6;
                        --surface: #ffffff;
                        --ink: #18231d;
                        --muted: #5b665f;
                        --line: #d6ddd8;
                        --accent: #087f5b;
                        --focus: #d9480f;
                    }

                    * {
                        box-sizing: border-box;
                    }

                    html {
                        background: var(--page);
                        color: var(--ink);
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                        line-height: 1.6;
                    }

                    body {
                        margin: 0;
                    }

                    a {
                        color: inherit;
                        text-decoration-color: var(--accent);
                        text-decoration-thickness: 0.1em;
                        text-underline-offset: 0.18em;
                    }

                    a:hover {
                        color: var(--accent);
                    }

                    a:focus-visible {
                        border-radius: 2px;
                        outline: 3px solid var(--focus);
                        outline-offset: 4px;
                    }

                    .site-header {
                        background: var(--surface);
                        border-bottom: 1px solid var(--line);
                        padding: 2.5rem 1.25rem 2rem;
                    }

                    .container {
                        margin: 0 auto;
                        max-width: 58rem;
                        width: 100%;
                    }

                    .protocol {
                        color: var(--accent);
                        font-size: 0.78rem;
                        font-weight: 700;
                        margin: 0 0 0.5rem;
                        text-transform: uppercase;
                    }

                    h1,
                    h2,
                    p {
                        overflow-wrap: anywhere;
                    }

                    h1 {
                        font-size: 2.125rem;
                        line-height: 1.2;
                        margin: 0;
                    }

                    h1 a,
                    h2 a {
                        text-decoration: none;
                    }

                    .channel-summary {
                        color: var(--muted);
                        margin: 0.75rem 0 0;
                        max-width: 46rem;
                    }

                    main {
                        padding: 0 1.25rem 3rem;
                    }

                    .feed-item {
                        border-bottom: 1px solid var(--line);
                        padding: 1.75rem 0;
                    }

                    .feed-item.has-media {
                        align-items: start;
                        display: grid;
                        gap: 1.5rem;
                        grid-template-columns: minmax(0, 1fr) 11rem;
                    }

                    h2 {
                        font-size: 1.25rem;
                        line-height: 1.35;
                        margin: 0;
                    }

                    .metadata {
                        color: var(--muted);
                        display: flex;
                        flex-wrap: wrap;
                        font-size: 0.875rem;
                        gap: 0.35rem 1rem;
                        margin-top: 0.45rem;
                    }

                    .summary {
                        color: var(--muted);
                        margin: 0.85rem 0 0;
                        white-space: normal;
                    }

                    .media {
                        display: block;
                    }

                    .media img {
                        aspect-ratio: 3 / 2;
                        background: var(--line);
                        border-radius: 4px;
                        display: block;
                        height: auto;
                        object-fit: cover;
                        width: 100%;
                    }

                    .empty {
                        color: var(--muted);
                        margin: 2rem 0;
                    }

                    @media (max-width: 640px) {
                        .site-header {
                            padding-top: 1.75rem;
                        }

                        h1 {
                            font-size: 1.75rem;
                        }

                        .feed-item.has-media {
                            display: flex;
                            flex-direction: column;
                        }

                        .feed-item.has-media .media {
                            order: -1;
                            width: 100%;
                        }

                        .media img {
                            aspect-ratio: 16 / 9;
                        }
                    }

                    @media (prefers-color-scheme: dark) {
                        :root {
                            --page: #111713;
                            --surface: #18201b;
                            --ink: #f1f5f2;
                            --muted: #aebbb2;
                            --line: #354039;
                            --accent: #61d6a7;
                            --focus: #ff922b;
                        }
                    }
                </style>
            </head>
            <body>
                <header class="site-header">
                    <div class="container">
                        <p class="protocol">RSS 2.0</p>
                        <h1>
                            <a href="{rss/channel/link[1]}">
                                <xsl:value-of select="rss/channel/title[1]"/>
                            </a>
                        </h1>
                        <xsl:if test="normalize-space(rss/channel/description[1]) != ''">
                            <p class="channel-summary">
                                <xsl:value-of select="normalize-space(rss/channel/description[1])"/>
                            </p>
                        </xsl:if>
                    </div>
                </header>

                <main>
                    <div class="container">
                        <xsl:for-each select="rss/channel/item">
                            <article>
                                <xsl:attribute name="class">
                                    <xsl:text>feed-item</xsl:text>
                                    <xsl:if test="media:thumbnail/@url or media:content/@url">
                                        <xsl:text> has-media</xsl:text>
                                    </xsl:if>
                                </xsl:attribute>

                                <div class="entry-content">
                                    <h2>
                                        <a href="{link[1]}">
                                            <xsl:value-of select="title[1]"/>
                                        </a>
                                    </h2>

                                    <xsl:if test="dc:creator or author or pubDate">
                                        <div class="metadata">
                                            <xsl:if test="dc:creator or author">
                                                <span class="author">
                                                    <xsl:value-of select="(dc:creator | author)[1]"/>
                                                </span>
                                            </xsl:if>
                                            <xsl:if test="pubDate">
                                                <time><xsl:value-of select="pubDate[1]"/></time>
                                            </xsl:if>
                                        </div>
                                    </xsl:if>

                                    <xsl:if test="normalize-space(description[1]) != ''">
                                        <p class="summary">
                                            <xsl:value-of select="normalize-space(description[1])"/>
                                        </p>
                                    </xsl:if>
                                </div>

                                <xsl:if test="media:thumbnail/@url or media:content/@url">
                                    <a class="media" href="{link[1]}" tabindex="-1">
                                        <xsl:choose>
                                            <xsl:when test="media:thumbnail/@url">
                                                <img src="{media:thumbnail[1]/@url}" alt="" loading="lazy" decoding="async"/>
                                            </xsl:when>
                                            <xsl:otherwise>
                                                <img src="{media:content[1]/@url}" alt="" loading="lazy" decoding="async"/>
                                            </xsl:otherwise>
                                        </xsl:choose>
                                    </a>
                                </xsl:if>
                            </article>
                        </xsl:for-each>

                        <xsl:if test="not(rss/channel/item)">
                            <p class="empty">No entries are currently available.</p>
                        </xsl:if>
                    </div>
                </main>
            </body>
        </html>
    </xsl:template>
</xsl:stylesheet>
