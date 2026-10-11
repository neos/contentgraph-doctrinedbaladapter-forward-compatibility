# Neos.ContentGraph.DoctrineDbalAdapterForwardCompatibility

> Ships the content graph `neos/contentgraph-doctrinedbaladapter` of a newer Neos release for previous Neos version to ease upgrades 

Some upgrades like Neos 9.1 to Neos 9.2 require a full replay of the contentGraph projection. There is no way to avoid that no simple data transformation from one state/schema to the other possible.
One way might be to [replay the projection locally first](https://discuss.neos.io/t/one-possible-guide-to-replay-projection-locally-and-deploy-remotely/7230) and to sync your tables to production.
But each way so far is a bit disruptive and either requires a content freeze or if done naively directly on live crashes the whole website rendering.

This package makes the impossible possible. Based on a spark of an idea that in event sourcing we exactly have the capabilities to have multiple projections.
This package copies the original sources and adjust the namespace so we can effectively install the content graph twice just with different versions.
And there is a bit more than that, the package brings all the tooling and documentation needed to allow a smooth upgrade.

## How smooth can be a smooth upgrade?

If all steps are executed correctly, and you know a bit about your event sourced Neos this process allows to warmup the new projection tables in a separate table.
Any work on the system can continue including working on the content repository. Once the warmup (catchup) is complete the further steps allow to switch the main projection to the new tables.

The only slightest of hiccups can occur in the final step before the projection tables are renamed and when the old projection code is no longer deployed.
But any absolute atomic switch was considered to adventurous for Neos developers and would come with too many responsibilities.

## Supported Upgrades

| Forward Compatibility Version | Upgrades to Neos ContentGraph Version | Upgrades from Neos Version |
|-------------------------------|---------------------------------------|----------------------------|
| 9.2                           | 9.2                                   | 9.0, 9.1                   |


## Step by Step Upgrade from 9.0 or 9.1 to 9.2

### Step 1 **Local** Install and deploy the pre-patch

Keep Neos 9.0 or 9.1 installed. Only require this pre-patch.

```
composer require neos/contentgraph-doctrinedbaladapter-forward-compatibility
```

Then deploy the new package to the stage or production server and continue

### Step 2 **Remote** Setup the pre-patch

After installation run `./flow cr:status`, you should see something like

```
Event Store:
  Setup: OK
  Position: 6226

Subscriptions:
  contentGraph:
    Setup: OK
    Projection: ACTIVE at position 6226

...

  contentGraph_92:
    Setup: SETUP REQUIRED
    Projection: NEW at position 0
```

Here the "contentGraph_92" projection indicates we have the pre-patch installed but not setup and also not replayed.

Run the setup, this will create the new empty tables like `cr_default_p_92_graph_node`

```
`./flow cr:setup`
```

### Step 3 **Remote** Ensure graph projection can replay and no faulty events / race conditions exist

The new content graph is stricter than before and does no longer allow accidentally event sequences that are caused by a race condition and will no longer be possible in the first place.
To detect if there are any inconsistencies run:

```
# note that its cr_pre_upgrade not ./flow crupgrade:eventsstatus like in Neos 9.2
./flow crpreupgrade:eventsstatus
```

If any there are any required migrations backup your database and fix your events.
Fixing events directly on production might be a bit advantageous so you should consider making a test on stage or locally first if everything will truly work.

If everything is okay continue

### Step 4 **Remote** Replay the new projection (slow)

Be aware that this command will take some time regarding how many events you have.
It might be advised to run this without connected terminal which can abort.

```
./flow subscription:replay contentGraph_92
```

After completion the new tables like `cr_default_p_92_graph_node` are filled.

### Step 5 **Remote** validation

Run the status again

```
./flow cr:status
```

and validate that now both content graphs are `ACTIVE` and at the same position like the event store

```
Event Store:
  Setup: OK
  Position: 6226

Subscriptions:
  contentGraph:
    Setup: OK
    Projection: ACTIVE at position 6226

...

  contentGraph_92:
    Setup: OK
    Projection: ACTIVE at position 6226
```

### Step 6 **Local** Generate the doctrine migration

Before upgrading to the new Neos 9.2 version you must add the migration that will rename the database tables

```
./flow crprepatch:generatemigration <Vendor.Site>
```

The migration will backup all current graph tables like `cr_default_p_90_graph_node` and rename the new prepared ones to the current, removing the 92 suffix

Commit the migration file but do not deploy yet.

### Step 7 **Local** Remove the prepatch and install Neos 9.2

Remove the prepatch package and install Neos 9.2 and apply all other adjustments as per any regular upgrade.

### Step 8 **Remote** Deploy and run doctrine migrations

Your CI should already contain `./flow doctrine:migrate` so you dont need to do anything than to deploy the new code and see that Neos 9.2 works by using its new tables.
