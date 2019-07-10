=========================================
Automatically generated redirects in Neos
=========================================

Whenever you change the `URL path segment` or move a document node, a redirect will automatically be generated as soon as it is published into the live workspace.

.. note:: To get an overview over all currently active redirects you can always run ``./flow redirect:list``. For further details check the `Neos Command Reference`.

Possible configuration for redirects
------------------------------------

You can configure the default behaviour for automatically generated redirects within ``Settings.yaml``.

.. code-block:: yaml

  Neos:
   RedirectHandler:
    features:
      hitCounter: true
    statusCode:
      'redirect': 307
      'gone': 410


Options
^^^^^^^

``hitCounter``
  turn on/off the hit counter for redirects.
``statusCode``
  define the default status code for redirect or gone status (node deleted).


It is also possible to add, change or remove redirects within the CLI.
The available CLI commands for custom redirect management can be found in the `Neos Command Reference`.


Restrict generation
-------------------

It is possible to restrict the generation of redirects to a certain node path or node type. For instance, you can user
in an multi site environment or avoid massive redirect generation if you don't need it.

restrictByNodeType
^^^^^^^^^^^^^^^^^^

Restrict redirect generation by node type.

.. code-block:: yaml

  restrictByNodeType:
    Neos.Neos:Document: true

restrictByPathPrefix
^^^^^^^^^^^^^^^^^^^^

Restrict redirect generation by node path prefix.

**Note**: No redirect will be created if you move a node within the restricted path or if you move it away from the
restricted path. But if you move a node into the restricted path the restriction rule will not apply, because the
restriction is based on the source node path.

.. code-block:: yaml

  restrictByPathPrefix:
    - '/sites/neosdemo': true

restrictByOldUriPrefix
^^^^^^^^^^^^^^^^^^^^^^

Restrict redirect generation by old URI prefix.

.. code-block:: yaml

  restrictByOldUriPrefix:
    '/some/uri/path': true
