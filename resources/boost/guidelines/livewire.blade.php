@if($assist->hasPackage('livewire/livewire', '^4.0'))
## Livewire 4

- Livewire 4 derives its update endpoint from APP_KEY (`/livewire-<hash>/update`). Never hard-code `/livewire/update`. When re-registering the route with `Livewire::setUpdateRoute()`, use the `$path` the callback receives; in tests, resolve the URI with `Livewire::getUpdateUri()` and find the route by its `livewire.update` name suffix.
- `addError()` adds to the error bag, which keeps earlier messages until the next validation or `resetErrorBag()`. A method that reports its own errors without `validate()` resets the bag first, or a field keeps showing the earlier message instead of the new one.
@endif
