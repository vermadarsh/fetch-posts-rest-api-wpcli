# fetch-posts-rest-api-wpcli
1. Check for the site (A.com) and consume the API for the posts using wp-josn API
2. Create a custome wp-cli command on site (B.com), which will consume the following things from (A.com) site API endpoints and create post/user/tax as per the source on B.com site.
    - posts / pages / users / taxonomies (categories/tags)
    
Good to have (Experimental things)
- attachments
- Featured images
- Inline images from post-content (Use DomDocument helpers)
- Comments for all posts/pages
- CPTs