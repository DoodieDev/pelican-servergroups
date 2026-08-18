# Server Groups

Server Groups adds global, visual groups to the Pelican server list. Administrators can create groups, choose their color, set their order, assign accessible servers, and grant existing users per-group Pelican permissions. A server can belong to one group at a time.

## Import

1. Open the Pelican administrator area.
2. Open **Plugins** and choose **Import from file**.
3. Select `pelican-server-groups.zip`.
4. Install and enable the imported plugin.
5. Open **Server Groups** from the administrator navigation.

Group user access is virtual and follows the group membership, so moving a server between groups updates access automatically without creating duplicate core subuser records. Disabling or uninstalling it removes only its own grouping and group-access data.
