<tr>
    <td>{{ $label[$item->url] ?? $item->nama }}@if ($induk)<span class="text-muted small"> · {{ $induk->nama }}</span>@endif</td>
    @foreach (\App\Models\Privilege::AKSI as $a)
        <td class="text-center"><input type="checkbox" class="form-check-input" name="akses[{{ $item->id }}][{{ $a }}]" value="1" @checked($priv[$item->id]?->{'can_' . $a} ?? false)></td>
    @endforeach
</tr>
